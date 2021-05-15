<?php

namespace OwenIt\Auditing;

use DateTimeInterface;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\AttributeEncoder;

trait Audit
{
    /**
     * Audit data.
     *
     * @var array
     */
    protected $data = [];

    /**
     * The Audit attributes that belong to the metadata.
     *
     * @var array
     */
    protected $metadata = [];

    /**
     * The Auditable attributes that were modified.
     *
     * @var array
     */
    protected $modified = [];

    /**
     * {@inheritdoc}
     */
    public function AUDITABLE()
    {
        return $this->morphTo();
    }

    /**
     * {@inheritdoc}
     */
    public function user()
    {
        return $this->morphTo();
    }

    /**
     * {@inheritdoc}
     */
    public function getConnectionName()
    {
        return Config::get('audit.drivers.database.connection');
    }

    /**
     * {@inheritdoc}
     */
    public function getTable(): string
    {
        return Config::get('audit.drivers.database.table', parent::getTable());
    }

    /**
     * {@inheritdoc}
     */
    public function resolveData(): array
    {
        $morphPrefix = Config::get('audit.user.morph_prefix', 'user');

        // Metadata
        $this->data = [
            'audit_id'         => $this->AUDIT_TRAILS_ID,
            'audit_event'      => $this->EVENT,
            'audit_url'        => $this->URL,
            'audit_ip_address' => $this->IP_ADDRESS,
            'audit_user_agent' => $this->BROWSER,
            'audit_tags'       => $this->TAGS,
            'audit_created_at' => $this->serializeDate($this->created_at),
            'audit_updated_at' => $this->serializeDate($this->updated_at),
            'USER_ID'          => $this->getAttribute($morphPrefix.'_id'),
            'USER_MODEL'        => $this->getAttribute($morphPrefix.'_type'),
        ];

        if ($this->user) {
            foreach ($this->user->getArrayableAttributes() as $attribute => $value) {
                $this->data['user_'.$attribute] = $value;
            }
        }

        $this->metadata = array_keys($this->data);

        // Modified Auditable attributes
        foreach ($this->NEW_VALUES as $key => $value) {
            $this->data['new_'.$key] = $value;
        }

        foreach ($this->OLD_VALUES as $key => $value) {
            $this->data['old_'.$key] = $value;
        }

        $this->modified = array_diff_key(array_keys($this->data), $this->metadata);

        return $this->data;
    }

    /**
     * Get the formatted value of an Eloquent model.
     *
     * @param Model  $model
     * @param string $key
     * @param mixed  $value
     *
     * @return mixed
     */
    protected function getFormattedValue(Model $model, string $key, $value)
    {
        // Apply defined get mutator
        if ($model->hasGetMutator($key)) {
            return $model->mutateAttribute($key, $value);
        }

        // Cast to native PHP type
        if ($model->hasCast($key)) {
            return $model->castAttribute($key, $value);
        }

        // Honour DateTime attribute
        if ($value !== null && in_array($key, $model->getDates(), true)) {
            return $model->asDateTime($value);
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getDataValue(string $key)
    {
        if (!array_key_exists($key, $this->data)) {
            return;
        }

        $value = $this->data[$key];

        // User value
        if ($this->user && Str::startsWith($key, 'user_')) {
            return $this->getFormattedValue($this->user, substr($key, 5), $value);
        }

        // Auditable value
        if ($this->AUDITABLE && Str::startsWith($key, ['new_', 'old_'])) {
            $attribute = substr($key, 4);

            return $this->getFormattedValue(
                $this->AUDITABLE,
                $attribute,
                $this->decodeAttributeValue($this->AUDITABLE, $attribute, $value)
            );
        }

        return $value;
    }

    /**
     * Decode attribute value.
     *
     * @param Contracts\Auditable $AUDITABLE
     * @param string              $attribute
     * @param mixed               $value
     *
     * @return mixed
     */
    protected function decodeAttributeValue(Contracts\Auditable $AUDITABLE, string $attribute, $value)
    {
        $attributeModifiers = $AUDITABLE->getAttributeModifiers();

        if (!array_key_exists($attribute, $attributeModifiers)) {
            return $value;
        }

        $attributeDecoder = $attributeModifiers[$attribute];

        if (is_subclass_of($attributeDecoder, AttributeEncoder::class)) {
            return call_user_func([$attributeDecoder, 'decode'], $value);
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(bool $json = false, int $options = 0, int $depth = 512)
    {
        if (empty($this->data)) {
            $this->resolveData();
        }

        $metadata = [];

        foreach ($this->metadata as $key) {
            $value = $this->getDataValue($key);

            $metadata[$key] = $value instanceof DateTimeInterface
                ? $this->serializeDate($value)
                : $value;
        }

        return $json ? json_encode($metadata, $options, $depth) : $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function getModified(bool $json = false, int $options = 0, int $depth = 512)
    {
        if (empty($this->data)) {
            $this->resolveData();
        }

        $modified = [];

        foreach ($this->modified as $key) {
            $attribute = substr($key, 4);
            $state = substr($key, 0, 3);

            $value = $this->getDataValue($key);

            $modified[$attribute][$state] = $value instanceof DateTimeInterface
                ? $this->serializeDate($value)
                : $value;
        }

        return $json ? json_encode($modified, $options, $depth) : $modified;
    }

    /**
     * Get the Audit TAGS as an array.
     *
     * @return array
     */
    public function getTags(): array
    {
        return preg_split('/,/', $this->TAGS, null, PREG_SPLIT_NO_EMPTY);
    }
}
