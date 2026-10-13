<?php

declare(strict_types=1);

namespace Deployer\Tests\Api;

use Deployer\Tests\Support\ApiTestCase;

final class InstructionsApiTest extends ApiTestCase
{
    private const VALID = "steps:\n  - name: Build\n    run: make\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdmin();
    }

    public function testReadsTheFileAndReportsValidity(): void
    {
        $project = $this->createProjectViaApi('shop', "steps:\n  - name: Bad\n    run: echo \${MISSING}\n");

        $response = $this->call('GET', '/api/projects/' . $project['id'] . '/instructions');

        self::assertSame(200, $response->status);
        $data = $this->json($response);
        self::assertTrue($data['exists']);
        self::assertSame($project['folder'] . '/deployer.yml', $data['path']);
        self::assertStringContainsString('MISSING', $data['content']);
        self::assertFalse($data['valid']);
        self::assertNotEmpty($data['errors']);
    }

    public function testMissingFileIsReportedNotAnError(): void
    {
        $project = $this->createProjectViaApi('empty', null);

        $data = $this->json($this->call('GET', '/api/projects/' . $project['id'] . '/instructions'));

        self::assertFalse($data['exists']);
        self::assertSame('', $data['content']);
    }

    public function testValidContentIsWrittenToDisk(): void
    {
        $project = $this->createProjectViaApi('shop', null);

        $response = $this->call('PUT', '/api/projects/' . $project['id'] . '/instructions', ['content' => self::VALID]);

        self::assertSame(200, $response->status, $response->body);
        self::assertTrue($this->json($response)['valid']);
        self::assertSame(self::VALID, file_get_contents($project['folder'] . '/deployer.yml'));
    }

    public function testInvalidContentIsRejectedUnlessForced(): void
    {
        $project = $this->createProjectViaApi('shop');
        $original = file_get_contents($project['folder'] . '/deployer.yml');
        $invalid = "steps:\n  - name: X\n    run: echo \${NOPE}\n    timout: 3\n";

        $rejected = $this->call('PUT', '/api/projects/' . $project['id'] . '/instructions', ['content' => $invalid]);

        self::assertSame(422, $rejected->status);
        self::assertSame('invalid_pipeline', $this->json($rejected)['error']['code']);
        self::assertCount(2, $this->json($rejected)['error']['details']['errors']);
        self::assertSame($original, file_get_contents($project['folder'] . '/deployer.yml'));

        $forced = $this->call('PUT', '/api/projects/' . $project['id'] . '/instructions', ['content' => $invalid], query: ['force' => '1']);

        self::assertSame(200, $forced->status);
        self::assertFalse($this->json($forced)['valid']);
        self::assertSame($invalid, file_get_contents($project['folder'] . '/deployer.yml'));
    }

    public function testRelativePathCannotEscapeTheFolderThroughASymlink(): void
    {
        $outside = $this->makeTempDir();
        $project = $this->createProjectViaApi('shop', null, ['instructions_file' => 'link/deployer.yml']);
        symlink($outside, $project['folder'] . '/link');

        $write = $this->call('PUT', '/api/projects/' . $project['id'] . '/instructions', ['content' => self::VALID]);
        $read = $this->call('GET', '/api/projects/' . $project['id'] . '/instructions');

        self::assertSame(409, $write->status);
        self::assertSame('instructions_path_outside_folder', $this->json($write)['error']['code']);
        self::assertFileDoesNotExist($outside . '/deployer.yml');
        self::assertSame(409, $read->status);
    }

    public function testMissingFolderCannotBeWritten(): void
    {
        $response = $this->call('POST', '/api/projects', ['name' => 'Ghost', 'slug' => 'ghost', 'folder' => '/nonexistent/deployer-ghost']);
        $id = $this->json($response)['project']['id'];

        $write = $this->call('PUT', '/api/projects/' . $id . '/instructions', ['content' => self::VALID]);

        self::assertSame(409, $write->status);
        self::assertSame('instructions_not_writable', $this->json($write)['error']['code']);
    }

    public function testContentMustBeAString(): void
    {
        $project = $this->createProjectViaApi('shop');

        self::assertSame(422, $this->call('PUT', '/api/projects/' . $project['id'] . '/instructions', ['content' => 5])->status);
    }
}
