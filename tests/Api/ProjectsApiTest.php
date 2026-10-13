<?php

declare(strict_types=1);

namespace Deployer\Tests\Api;

use Deployer\Tests\Support\ApiTestCase;

final class ProjectsApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    public function testCrud(): void
    {
        $project = $this->createProjectViaApi('shop');

        self::assertSame('shop', $project['slug']);
        self::assertSame('deployer.yml', $project['instructions_file']);
        self::assertSame($project['folder'] . '/deployer.yml', $project['instructions_path']);
        self::assertFalse($project['has_webhook_token']);
        self::assertSame('/hooks/shop', $project['webhook_path']);
        self::assertNull($project['notifier_id']);

        self::assertSame([$project], $this->json($this->call('GET', '/api/projects'))['projects']);

        $updated = $this->call('PUT', '/api/projects/' . $project['id'], ['name' => 'Shop v2', 'instructions_file' => 'ci/deploy.yml']);
        self::assertSame(200, $updated->status, $updated->body);
        self::assertSame('Shop v2', $this->json($updated)['project']['name']);
        self::assertSame('shop', $this->json($updated)['project']['slug']);
        self::assertSame($project['folder'] . '/ci/deploy.yml', $this->json($updated)['project']['instructions_path']);

        self::assertSame(204, $this->call('DELETE', '/api/projects/' . $project['id'])->status);
        self::assertSame(404, $this->call('GET', '/api/projects/' . $project['id'])->status);
    }

    public function testValidation(): void
    {
        $response = $this->call('POST', '/api/projects', [
            'name' => '',
            'slug' => 'Bad Slug!',
            'folder' => 'relative/path',
            'instructions_file' => '../outside.yml',
            'notifier_id' => 99,
        ]);

        self::assertSame(422, $response->status);
        self::assertSame(
            ['name', 'slug', 'folder', 'instructions_file', 'notifier_id'],
            array_keys($this->json($response)['error']['details']),
        );
    }

    public function testAbsoluteInstructionsPathIsAllowed(): void
    {
        $project = $this->createProjectViaApi('abs', extra: ['instructions_file' => '/etc/deployer/abs.yml']);

        self::assertSame('/etc/deployer/abs.yml', $project['instructions_path']);
    }

    public function testDuplicateSlugConflicts(): void
    {
        $this->createProjectViaApi('shop');

        $response = $this->call('POST', '/api/projects', ['name' => 'Other', 'slug' => 'shop', 'folder' => '/tmp']);

        self::assertSame(409, $response->status);
        self::assertSame('slug_taken', $this->json($response)['error']['code']);
    }

    public function testSlugDefaultsFromName(): void
    {
        $response = $this->call('POST', '/api/projects', ['name' => 'My Shop API', 'folder' => '/srv/shop']);

        self::assertSame('my-shop-api', $this->json($response)['project']['slug']);
    }

    public function testProjectWithActiveRunsCannotBeDeleted(): void
    {
        $project = $this->createProjectViaApi('busy');
        self::assertSame(202, $this->call('POST', '/api/projects/' . $project['id'] . '/runs')->status);

        $response = $this->call('DELETE', '/api/projects/' . $project['id']);

        self::assertSame(409, $response->status);
        self::assertSame('project_busy', $this->json($response)['error']['code']);
    }

    public function testRejectsUnknownIdsAndNonNumericIds(): void
    {
        self::assertSame(404, $this->call('GET', '/api/projects/42')->status);
        self::assertSame(404, $this->call('GET', '/api/projects/abc')->status);
    }
}
