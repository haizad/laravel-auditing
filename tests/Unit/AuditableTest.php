<?php

namespace OwenIt\Auditing\Tests;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\Assert;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Encoders\Base64Encoder;
use OwenIt\Auditing\Exceptions\AuditableTransitionException;
use OwenIt\Auditing\Exceptions\AuditingException;
use OwenIt\Auditing\Models\Audit;
use OwenIt\Auditing\Redactors\LeftRedactor;
use OwenIt\Auditing\Redactors\RightRedactor;
use OwenIt\Auditing\Tests\Models\ApiModel;
use OwenIt\Auditing\Tests\Models\Article;
use OwenIt\Auditing\Tests\Models\User;
use ReflectionClass;

class AuditableTest extends AuditingTestCase
{
    /**
     * {@inheritdoc}
     */
    public function setUp(): void
    {
        parent::setUp();

        // Clear morph maps
        Relation::morphMap([], false);
    }

    /**
     * @group Auditable::isAuditingEnabled
     * @test
     */
    public function itWillNotAuditModelsWhenRunningFromTheConsole()
    {
        $this->app['config']->set('audit.console', false);

        $this->assertFalse(Article::isAuditingEnabled());
    }

    /**
     * @group Auditable::isAuditingEnabled
     * @test
     */
    public function itWillAuditModelsWhenRunningFromTheConsole()
    {
        $this->app['config']->set('audit.console', true);

        $this->assertTrue(Article::isAuditingEnabled());
    }

    /**
     * @group Auditable::isAuditingEnabled
     * @test
     */
    public function itWillAlwaysAuditModelsWhenNotRunningFromTheConsole()
    {
        App::shouldReceive('runningInConsole')
            ->andReturn(false);

        $this->app['config']->set('audit.console', false);

        $this->assertTrue(Article::isAuditingEnabled());
    }

    /**
     * @group Auditable::bootAuditable
     * @test
     */
    public function itWillNotBootTraitWhenStaticFlagIsSet()
    {
        App::spy();

        Article::$auditingDisabled = true;

        new Article();

        App::shouldNotHaveReceived('runningInConsole');

        Article::$auditingDisabled = false;
    }

    /**
     * @group Auditable::getAuditEvent
     * @test
     */
    public function itReturnsNullWhenTheAuditEventIsNotSet()
    {
        $model = new Article();

        $this->assertNull($model->getAuditEvent());
    }

    /**
     * @group Auditable::getAuditEvent
     * @test
     */
    public function itReturnsTheAuditEventThatHasBeenSet()
    {
        $model = new Article();
        $model->setAuditEvent('created');

        $this->assertSame('created', $model->getAuditEvent());
    }

    /**
     * @group Auditable::getAuditEvents
     * @test
     */
    public function itReturnsTheDefaultAuditEvents()
    {
        $model = new Article();

        Assert::assertArraySubset([
            'created',
            'updated',
            'deleted',
            'restored',
        ], $model->getAuditEvents(), true);
    }

    /**
     * @group Auditable::getAuditEvents
     * @test
     */
    public function itReturnsTheCustomAuditEventsFromAttribute()
    {
        $model = new Article();

        $model->auditEvents = [
            'published' => 'getPublishedEventAttributes',
            'archived',
        ];

        Assert::assertArraySubset([
            'published' => 'getPublishedEventAttributes',
            'archived',
        ], $model->getAuditEvents(), true);
    }

    /**
     * @group Auditable::getAuditEvents
     * @test
     */
    public function itReturnsTheCustomAuditEventsFromConfig()
    {
        $this->app['config']->set('audit.events', [
            'published' => 'getPublishedEventAttributes',
            'archived',
        ]);

        $model = new Article();

        Assert::assertArraySubset([
            'published' => 'getPublishedEventAttributes',
            'archived',
        ], $model->getAuditEvents(), true);
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::readyForAuditing
     * @test
     */
    public function itIsNotReadyForAuditingWithCustomEvent()
    {
        $model = new Article();

        $model->setAuditEvent('published');
        $this->assertFalse($model->readyForAuditing());
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::readyForAuditing
     * @test
     */
    public function itIsReadyForAuditingWithCustomEvents()
    {
        $model = new Article();

        $model->auditEvents = [
            'published' => 'getPublishedEventAttributes',
            '*ted'      => 'getMultiEventAttributes',
            'archived',
        ];

        $model->setAuditEvent('published');
        $this->assertTrue($model->readyForAuditing());

        $model->setAuditEvent('archived');
        $this->assertTrue($model->readyForAuditing());

        $model->setAuditEvent('redacted');
        $this->assertTrue($model->readyForAuditing());
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::readyForAuditing
     * @test
     */
    public function itIsReadyForAuditingWithRegularEvents()
    {
        $model = new Article();

        $model->setAuditEvent('created');
        $this->assertTrue($model->readyForAuditing());

        $model->setAuditEvent('updated');
        $this->assertTrue($model->readyForAuditing());

        $model->setAuditEvent('deleted');
        $this->assertTrue($model->readyForAuditing());

        $model->setAuditEvent('restored');
        $this->assertTrue($model->readyForAuditing());
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     */
    public function itFailsWhenAnInvalidAuditEventIsSet()
    {
        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('A valid audit EVENT has not been set');

        $model = new Article();

        $model->setAuditEvent('published');

        $model->toAudit();
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     *
     * @dataProvider auditCustomAttributeGetterFailTestProvider
     *
     * @param string $EVENT
     * @param array  $auditEvents
     * @param string $exceptionMessage
     */
    public function itFailsWhenTheCustomAttributeGettersAreMissing(
        string $EVENT,
        array $auditEvents,
        string $exceptionMessage
    ) {
        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $model = new Article();

        $model->auditEvents = $auditEvents;

        $model->setAuditEvent($EVENT);

        $model->toAudit();
    }

    /**
     * @return array
     */
    public function auditCustomAttributeGetterFailTestProvider(): array
    {
        return [
            [
                'published',
                [
                    'published' => 'getPublishedEventAttributes',
                ],
                'Unable to handle "published" EVENT, getPublishedEventAttributes() method missing',
            ],
            [
                'archived',
                [
                    'archived',
                ],
                'Unable to handle "archived" EVENT, getArchivedEventAttributes() method missing',
            ],
            [
                'redacted',
                [
                    '*ed',
                ],
                'Unable to handle "redacted" EVENT, getRedactedEventAttributes() method missing',
            ],
            [
                'redacted',
                [
                    '*ed' => 'getMultiEventAttributes',
                ],
                'Unable to handle "redacted" EVENT, getMultiEventAttributes() method missing',
            ],
        ];
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     */
    public function itFailsWhenTheIpAddressResolverImplementationIsInvalid()
    {
        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('Invalid IpAddressResolver implementation');

        $this->app['config']->set('audit.resolver.IP_ADDRESS', null);

        $model = new Article();

        $model->setAuditEvent('created');

        $model->toAudit();
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     */
    public function itFailsWhenTheUrlResolverImplementationIsInvalid()
    {
        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('Invalid UrlResolver implementation');

        $this->app['config']->set('audit.resolver.URL', null);

        $model = new Article();

        $model->setAuditEvent('created');

        $model->toAudit();
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     */
    public function itFailsWhenTheUserAgentResolverImplementationIsInvalid()
    {
        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('Invalid UserAgentResolver implementation');

        $this->app['config']->set('audit.resolver.BROWSER', null);

        $model = new Article();

        $model->setAuditEvent('created');

        $model->toAudit();
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     */
    public function itFailsWhenTheUserResolverImplementationIsInvalid()
    {
        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('Invalid UserResolver implementation');

        $this->app['config']->set('audit.resolver.user', null);

        $model = new Article();

        $model->setAuditEvent('created');

        $model->toAudit();
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     */
    public function itReturnsTheAuditData()
    {
        $now = Carbon::now();

        $model = factory(Article::class)->make([
            'title'        => 'How To Audit Eloquent Models',
            'content'      => 'First step: install the laravel-auditing package.',
            'reviewed'     => 1,
            'published_at' => $now,
        ]);

        $model->setAuditEvent('created');

        $this->assertCount(11, $auditData = $model->toAudit());

        Assert::assertArraySubset([
            'OLD_VALUES' => [],
            'NEW_VALUES' => [
                'title'        => 'How To Audit Eloquent Models',
                'content'      => 'First step: install the laravel-auditing package.',
                'reviewed'     => 1,
                'published_at' => $now->toDateTimeString(),
            ],
            'EVENT'          => 'created',
            'AUDIT_ID'   => null,
            'auditable_type' => Article::class,
            'USER_ID'        => null,
            'USER_MODEL'      => null,
            'URL'            => 'console',
            'IP_ADDRESS'     => '127.0.0.1',
            'BROWSER'     => 'Symfony',
            'TAGS'           => null,
        ], $auditData, true);
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     *
     * @dataProvider userResolverProvider
     *
     * @param string $guard
     * @param string $driver
     * @param int    $AUDIT_TRAILS_ID
     * @param string $type
     */
    public function itReturnsTheAuditDataIncludingUserAttributes(
        string $guard,
        string $driver,
        int $AUDIT_TRAILS_ID = null,
        string $type = null
    ) {
        $this->app['config']->set('audit.user.guards', [
            $guard,
        ]);

        $user = factory(User::class)->create();

        $this->actingAs($user, $driver);

        $now = Carbon::now();

        $model = factory(Article::class)->make([
            'title'        => 'How To Audit Eloquent Models',
            'content'      => 'First step: install the laravel-auditing package.',
            'reviewed'     => 1,
            'published_at' => $now,
        ]);

        $model->setAuditEvent('created');

        $this->assertCount(11, $auditData = $model->toAudit());

        Assert::assertArraySubset([
            'OLD_VALUES' => [],
            'NEW_VALUES' => [
                'title'        => 'How To Audit Eloquent Models',
                'content'      => 'First step: install the laravel-auditing package.',
                'reviewed'     => 1,
                'published_at' => $now->toDateTimeString(),
            ],
            'EVENT'          => 'created',
            'AUDIT_ID'   => null,
            'auditable_type' => Article::class,
            'USER_ID'        => $AUDIT_TRAILS_ID,
            'USER_MODEL'      => $type,
            'URL'            => 'console',
            'IP_ADDRESS'     => '127.0.0.1',
            'BROWSER'     => 'Symfony',
            'TAGS'           => null,
        ], $auditData, true);
    }

    /**
     * @return array
     */
    public function userResolverProvider(): array
    {
        return [
            [
                'api',
                'web',
                null,
                null,
            ],
            [
                'web',
                'api',
                null,
                null,
            ],
            [
                'api',
                'api',
                1,
                User::class,
            ],
            [
                'web',
                'web',
                1,
                User::class,
            ],
        ];
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     */
    public function itExcludesAttributesFromTheAuditDataWhenInStrictMode()
    {
        $this->app['config']->set('audit.strict', true);

        $model = factory(Article::class)->make([
            'title'        => 'How To Audit Eloquent Models',
            'content'      => 'First step: install the laravel-auditing package.',
            'reviewed'     => 1,
            'published_at' => Carbon::now(),
        ]);

        $model->setHidden([
            'reviewed',
        ]);

        $model->setVisible([
            'title',
            'content',
        ]);

        $model->setAuditEvent('created');

        $this->assertCount(11, $auditData = $model->toAudit());

        Assert::assertArraySubset([
            'OLD_VALUES' => [],
            'NEW_VALUES' => [
                'title'   => 'How To Audit Eloquent Models',
                'content' => 'First step: install the laravel-auditing package.',
            ],
            'EVENT'          => 'created',
            'AUDIT_ID'   => null,
            'auditable_type' => Article::class,
            'USER_ID'        => null,
            'USER_MODEL'      => null,
            'URL'            => 'console',
            'IP_ADDRESS'     => '127.0.0.1',
            'BROWSER'     => 'Symfony',
            'TAGS'           => null,
        ], $auditData, true);
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     */
    public function itFailsWhenTheAttributeModifierImplementationIsInvalid()
    {
        $this->expectException(AuditingException::class);
        $this->expectExceptionMessage('Invalid AttributeModifier implementation: invalidAttributeRedactorOrEncoder');

        $model = factory(Article::class)->make();

        $model->attributeModifiers = [
            'title' => 'invalidAttributeRedactorOrEncoder',
        ];

        $model->setAuditEvent('created');

        $model->toAudit();
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::toAudit
     * @test
     */
    public function itModifiesTheAuditAttributesSuccessfully()
    {
        $model = factory(Article::class)->make([
            'title'        => 'How To Audit Models',
            'content'      => 'N/A',
            'reviewed'     => 0,
            'published_at' => null,
        ]);

        $now = Carbon::now();

        $model->syncOriginal();

        $model->title = 'How To Audit Eloquent Models';
        $model->content = 'First step: install the laravel-auditing package.';
        $model->reviewed = 1;
        $model->published_at = $now;

        $model->setAuditEvent('updated');

        $model->attributeModifiers = [
            'title'    => RightRedactor::class,
            'content'  => LeftRedactor::class,
            'reviewed' => Base64Encoder::class,
        ];

        Assert::assertArraySubset([
            'OLD_VALUES' => [
                'title'        => 'Ho#################',
                'content'      => '##A',
                'published_at' => null,
                'reviewed'     => 'MA==',
            ],
            'NEW_VALUES' => [
                'title'        => 'How#########################',
                'content'      => '############################################kage.',
                'published_at' => $now->toDateTimeString(),
                'reviewed'     => 'MQ==',
            ],
        ], $model->toAudit(), true);
    }

    /**
     * @group Auditable::setAuditEvent
     * @group Auditable::transformAudit
     * @group Auditable::toAudit
     * @test
     */
    public function itTransformsTheAuditData()
    {
        $model = new class() extends Article {
            protected $attributes = [
                'title'        => 'How To Audit Eloquent Models',
                'content'      => 'First step: install the laravel-auditing package.',
                'reviewed'     => 1,
                'published_at' => '2012-06-14 15:03:00',
            ];

            public function transformAudit(array $data): array
            {
                $data['NEW_VALUES']['slug'] = Str::slug($data['NEW_VALUES']['title']);

                return $data;
            }
        };

        $model->setAuditEvent('created');

        $this->assertCount(11, $auditData = $model->toAudit());

        Assert::assertArraySubset([
            'NEW_VALUES' => [
                'title'        => 'How To Audit Eloquent Models',
                'content'      => 'First step: install the laravel-auditing package.',
                'reviewed'     => 1,
                'published_at' => '2012-06-14 15:03:00',
                'slug'         => 'how-to-audit-eloquent-models',
            ],
        ], $auditData, true);
    }

    /**
     * @group Auditable::getAuditInclude
     * @test
     */
    public function itReturnsTheDefaultAttributesToBeIncludedInTheAudit()
    {
        $model = new Article();

        Assert::assertArraySubset([], $model->getAuditInclude(), true);
    }

    /**
     * @group Auditable::getAuditInclude
     * @test
     */
    public function itReturnsTheCustomAttributesToBeIncludedInTheAudit()
    {
        $model = new Article();

        $model->auditInclude = [
            'title',
            'content',
        ];

        Assert::assertArraySubset([
            'title',
            'content',
        ], $model->getAuditInclude(), true);
    }

    /**
     * @group Auditable::getAuditExclude
     * @test
     */
    public function itReturnsTheDefaultAttributesToBeExcludedFromTheAudit()
    {
        $model = new Article();

        Assert::assertArraySubset([], $model->getAuditExclude(), true);
    }

    /**
     * @group Auditable::getAuditExclude
     * @test
     */
    public function itReturnsTheCustomAttributesToBeExcludedFromTheAudit()
    {
        $model = new Article();

        $model->auditExclude = [
            'published_at',
        ];

        Assert::assertArraySubset([
            'published_at',
        ], $model->getAuditExclude(), true);
    }

    /**
     * @group Auditable::getAuditStrict
     * @test
     */
    public function itReturnsTheDefaultAuditStrictValue()
    {
        $model = new Article();

        $this->assertFalse($model->getAuditStrict());
    }

    /**
     * @group Auditable::getAuditStrict
     * @test
     */
    public function itReturnsTheCustomAuditStrictValueFromAttribute()
    {
        $model = new Article();

        $model->auditStrict = true;

        $this->assertTrue($model->getAuditStrict());
    }

    /**
     * @group Auditable::getAuditStrict
     * @test
     */
    public function itReturnsTheCustomAuditStrictValueFromConfig()
    {
        $this->app['config']->set('audit.strict', true);

        $model = new Article();

        $this->assertTrue($model->getAuditStrict());
    }

    /**
     * @group Auditable::getAuditTimestamps
     * @test
     */
    public function itReturnsTheDefaultAuditTimestampsValue()
    {
        $model = new Article();

        $this->assertFalse($model->getAuditTimestamps());
    }

    /**
     * @group Auditable::getAuditTimestamps
     * @test
     */
    public function itReturnsTheCustomAuditTimestampsValueFromAttribute()
    {
        $model = new Article();

        $model->auditTimestamps = true;

        $this->assertTrue($model->getAuditTimestamps());
    }

    /**
     * @group Auditable::getAuditTimestamps
     * @test
     */
    public function itReturnsTheCustomAuditTimestampsValueFromConfig()
    {
        $this->app['config']->set('audit.timestamps', true);

        $model = new Article();

        $this->assertTrue($model->getAuditTimestamps());
    }

    /**
     * @group Auditable::getAuditDriver
     * @test
     */
    public function itReturnsTheDefaultAuditDriverValue()
    {
        $model = new Article();

        $this->assertSame('database', $model->getAuditDriver());
    }

    /**
     * @group Auditable::getAuditDriver
     * @test
     */
    public function itReturnsTheCustomAuditDriverValueFromAttribute()
    {
        $model = new Article();

        $model->auditDriver = 'RedisDriver';

        $this->assertSame('RedisDriver', $model->getAuditDriver());
    }

    /**
     * @group Auditable::getAuditDriver
     * @test
     */
    public function itReturnsTheCustomAuditDriverValueFromConfig()
    {
        $this->app['config']->set('audit.driver', 'RedisDriver');

        $model = new Article();

        $this->assertSame('RedisDriver', $model->getAuditDriver());
    }

    /**
     * @group Auditable::getAuditThreshold
     * @test
     */
    public function itReturnsTheDefaultAuditThresholdValue()
    {
        $model = new Article();

        $this->assertSame(0, $model->getAuditThreshold());
    }

    /**
     * @group Auditable::getAuditThreshold
     * @test
     */
    public function itReturnsTheCustomAuditThresholdValueFromAttribute()
    {
        $model = new Article();

        $model->auditThreshold = 10;

        $this->assertSame(10, $model->getAuditThreshold());
    }

    /**
     * @group Auditable::getAuditThreshold
     * @test
     */
    public function itReturnsTheCustomAuditThresholdValueFromConfig()
    {
        $this->app['config']->set('audit.threshold', 200);

        $model = new Article();

        $this->assertSame(200, $model->getAuditThreshold());
    }

    /**
     * @group Auditable::generateTags
     * @test
     */
    public function itReturnsTheDefaultGeneratedAuditTags()
    {
        $model = new Article();

        Assert::assertArraySubset([], $model->generateTags(), true);
    }

    /**
     * @group Auditable::generateTags
     * @test
     */
    public function itReturnsTheCustomGeneratedAuditTags()
    {
        $model = new class() extends Article {
            public function generateTags(): array
            {
                return [
                    'foo',
                    'bar',
                ];
            }
        };

        Assert::assertArraySubset([
            'foo',
            'bar',
        ], $model->generateTags(), true);
    }

    /**
     * @group Auditable::transitionTo
     * @test
     */
    public function itFailsToTransitionWhenTheAuditAuditableTypeDoesNotMatchTheModelType()
    {
        $this->expectException(AuditableTransitionException::class);
        $this->expectExceptionMessage('Expected Auditable type OwenIt\Auditing\Tests\Models\Article, got OwenIt\Auditing\Tests\Models\User instead');

        $audit = factory(Audit::class)->make([
            'auditable_type' => User::class,
        ]);

        $model = new Article();

        $model->transitionTo($audit);
    }

    /**
     * @group Auditable::transitionTo
     * @test
     */
    public function itFailsToTransitionWhenTheAuditAuditableTypeDoesNotMatchTheMorphMapValue()
    {
        $this->expectException(AuditableTransitionException::class);
        $this->expectExceptionMessage('Expected Auditable type articles, got users instead');

        Relation::morphMap([
            'articles' => Article::class,
        ]);

        $audit = factory(Audit::class)->make([
            'auditable_type' => 'users',
        ]);

        $model = new Article();

        $model->transitionTo($audit);
    }

    /**
     * @group Auditable::transitionTo
     * @test
     */
    public function itFailsToTransitionWhenTheAuditAuditableIdDoesNotMatchTheModelId()
    {
        $this->expectException(AuditableTransitionException::class);
        $this->expectExceptionMessage('Expected Auditable AUDIT_TRAILS_ID 2, got 1 instead');

        $firstAudit = factory(Article::class)->create()->AUDIT_TRAILS()->first();
        $secondModel = factory(Article::class)->create();

        $secondModel->transitionTo($firstAudit);
    }

    /**
     * @group Auditable::transitionTo
     * @test
     */
    public function itFailsToTransitionWhenTheAuditAuditableIdTypeDoesNotMatchTheModelIdType()
    {
        $this->expectException(AuditableTransitionException::class);
        $this->expectExceptionMessage('Expected Auditable AUDIT_TRAILS_ID 1, got 1 instead');

        $model = factory(Article::class)->create();

        $audit = factory(Audit::class)->create([
            'auditable_type' => Article::class,
            'AUDIT_ID'   => (string) $model->AUDIT_TRAILS_ID,
        ]);

        // Make sure the AUDIT_ID isn't being cast
        $auditReflection = new ReflectionClass($audit);

        $auditCastsProperty = $auditReflection->getProperty('casts');
        $auditCastsProperty->setAccessible(true);
        $auditCastsProperty->setValue($audit, [
            'OLD_VALUES' => 'json',
            'NEW_VALUES' => 'json',
        ]);

        $model->transitionTo($audit);
    }

    /**
     * @group Auditable::transitionTo
     * @test
     */
    public function itTransitionsWhenTheAuditAuditableIdTypeDoesNotMatchTheModelIdType()
    {
        $model = factory(Article::class)->create();

        // Key depends on type
        if ($model->getKeyType() == 'string') {
            $key = (string) $model->AUDIT_TRAILS_ID;
        } else {
            $key = (int) $model->AUDIT_TRAILS_ID;
        }

        $audit = factory(Audit::class)->create([
            'auditable_type' => Article::class,
            'AUDIT_ID'   => $key,
        ]);

        $this->assertInstanceOf(Auditable::class, $model->transitionTo($audit));
    }

    /**
     * @group Auditable::transitionTo
     * @test
     */
    public function itFailsToTransitionWhenAnAttributeRedactorIsSet()
    {
        $this->expectException(AuditableTransitionException::class);
        $this->expectExceptionMessage('Cannot transition states when an AttributeRedactor is set');

        $model = factory(Article::class)->create();

        $model->attributeModifiers = [
            'title' => RightRedactor::class,
        ];

        $audit = factory(Audit::class)->create([
            'AUDIT_ID'   => $model->getKey(),
            'auditable_type' => Article::class,
        ]);

        $model->transitionTo($audit);
    }

    /**
     * @group Auditable::transitionTo
     * @test
     */
    public function itFailsToTransitionWhenTheAuditableAttributeCompatibilityIsNotMet()
    {
        $model = factory(Article::class)->create();

        $incompatibleAudit = factory(Audit::class)->create([
            'EVENT'          => 'created',
            'AUDIT_ID'   => $model->getKey(),
            'auditable_type' => Article::class,
            'OLD_VALUES'     => [],
            'NEW_VALUES'     => [
                'subject' => 'Culpa qui rerum excepturi quisquam quia officiis.',
                'text'    => 'Magnam enim suscipit officiis tempore ut quis harum.',
            ],
        ]);

        try {
            $model->transitionTo($incompatibleAudit);
        } catch (AuditableTransitionException $e) {
            $this->assertSame(
                'Incompatibility between [OwenIt\Auditing\Tests\Models\Article:1] and [OwenIt\Auditing\Models\Audit:3]',
                $e->getMessage()
            );

            Assert::assertArraySubset([
                'subject',
                'text',
            ], $e->getIncompatibilities(), true);
        }
    }

    /**
     * @group Auditable::transitionTo
     * @test
     *
     * @dataProvider auditableTransitionTestProvider
     *
     * @param bool  $morphMap
     * @param array $oldValues
     * @param array $newValues
     * @param array $oldExpectation
     * @param array $newExpectation
     */
    public function itTransitionsToAnotherModelState(
        bool $morphMap,
        array $oldValues,
        array $newValues,
        array $oldExpectation,
        array $newExpectation
    ) {
        $models = factory(Article::class, 2)->create([
            'title'   => 'Facilis voluptas qui impedit deserunt vitae quidem.',
            'content' => 'Consectetur distinctio nihil eveniet cum. Expedita dolores animi dolorum eos repellat rerum.',
        ]);

        if ($morphMap) {
            Relation::morphMap([
                'articles' => Article::class,
            ]);
        }

        $auditableType = $morphMap ? 'articles' : Article::class;

        $AUDIT_TRAILS = $models->map(function (Article $model) use ($auditableType, $oldValues, $newValues) {
            return factory(Audit::class)->create([
                'AUDIT_ID'   => $model->getKey(),
                'auditable_type' => $auditableType,
                'OLD_VALUES'     => $oldValues,
                'NEW_VALUES'     => $newValues,
            ]);
        });

        // Transition with old values
        $this->assertInstanceOf(Auditable::class, $models[0]->transitionTo($AUDIT_TRAILS[0], true));
        $this->assertSame($oldExpectation, $models[0]->getDirty());

        // Transition with new values
        $this->assertInstanceOf(Auditable::class, $models[1]->transitionTo($AUDIT_TRAILS[1]));
        $this->assertSame($newExpectation, $models[1]->getDirty());
    }

    /**
     * @test
     */
    public function itWorksWithStringKeyModels()
    {
        $model = factory(ApiModel::class)->create();
        $model->save();
        $model->refresh();

        $this->assertCount(1, $model->AUDIT_TRAILS);

        $model->content = 'Something else';
        $model->save();
        $model->refresh();

        $this->assertCount(2, $model->AUDIT_TRAILS);
    }

    /**
     * @return array
     */
    public function auditableTransitionTestProvider(): array
    {
        return [
            //
            // Audit data and expectations for retrieved EVENT
            //
            [
                // Morph Map
                false,

                // Old values
                [],

                // New values
                [],

                // Expectation when transitioning with old values
                [],

                // Expectation when transitioning with new values
                [],
            ],

            //
            // Audit data and expectations for created/restored EVENT
            //
            [
                // Morph Map
                true,

                // Old values
                [],

                // New values
                [
                    'title'   => 'Nullam egestas interdum eleifend.',
                    'content' => 'Morbi consectetur laoreet sem, eu tempus odio tempor AUDIT_TRAILS_ID.',
                ],

                // Expectation when transitioning with old values
                [],

                // Expectation when transitioning with new values
                [
                    'title'   => 'NULLAM EGESTAS INTERDUM ELEIFEND.',
                    'content' => 'Morbi consectetur laoreet sem, eu tempus odio tempor AUDIT_TRAILS_ID.',
                ],
            ],

            //
            // Audit data and expectations for updated EVENT
            //
            [
                // Morph Map
                false,

                // Old values
                [
                    'title'   => 'Vivamus a urna et lorem faucibus malesuada nec nec magna.',
                    'content' => 'Mauris ipsum erat, semper non quam vel, sodales tincidunt ligula.',
                ],

                // New values
                [
                    'title'   => 'Nullam egestas interdum eleifend.',
                    'content' => 'Morbi consectetur laoreet sem, eu tempus odio tempor AUDIT_TRAILS_ID.',
                ],

                // Expectation when transitioning with old values
                [
                    'title'   => 'VIVAMUS A URNA ET LOREM FAUCIBUS MALESUADA NEC NEC MAGNA.',
                    'content' => 'Mauris ipsum erat, semper non quam vel, sodales tincidunt ligula.',
                ],

                // Expectation when transitioning with new values
                [
                    'title'   => 'NULLAM EGESTAS INTERDUM ELEIFEND.',
                    'content' => 'Morbi consectetur laoreet sem, eu tempus odio tempor AUDIT_TRAILS_ID.',
                ],
            ],

            //
            // Audit data and expectations for deleted EVENT
            //
            [
                // Morph Map
                true,

                // Old values
                [
                    'title'   => 'Vivamus a urna et lorem faucibus malesuada nec nec magna.',
                    'content' => 'Mauris ipsum erat, semper non quam vel, sodales tincidunt ligula.',
                ],

                // New values
                [],

                // Expectation when transitioning with old values
                [
                    'title'   => 'VIVAMUS A URNA ET LOREM FAUCIBUS MALESUADA NEC NEC MAGNA.',
                    'content' => 'Mauris ipsum erat, semper non quam vel, sodales tincidunt ligula.',
                ],

                // Expectation when transitioning with new values
                [],
            ],
        ];
    }
}
