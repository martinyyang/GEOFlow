<?php

namespace Tests\Feature;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\DistributionTargetSitePackageBuilder;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class DistributionTargetSitePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_site_package_contains_pagination_code_and_routes(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $channel = DistributionChannel::query()->create([
            'name' => 'Pagination Assertions Site',
            'domain' => '127.0.0.1',
            'endpoint_url' => 'http://127.0.0.1:8080/blog',
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'rewrite',
            'template_key' => 'default',
            'site_settings' => [
                'site_name' => 'Assert Blog',
                'per_page' => 2,
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_test_assert',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_test_secret'),
            'status' => 'active',
            'scopes' => ['frontend.capabilities'],
        ]);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_test_assert', 'gfsec_test_secret');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));

        $frontController = (string) $zip->getFromName('public/index.php');
        $siteCss = (string) $zip->getFromName('assets/css/site.css');

        $this->assertStringContainsString('function listTotalPages', $frontController);
        $this->assertStringContainsString('function listPagePath', $frontController);
        $this->assertStringContainsString('function listPageArticles', $frontController);
        $this->assertStringContainsString('function renderListPagination', $frontController);
        $this->assertStringContainsString('function pruneStaticListPages', $frontController);
        $this->assertStringContainsString("'/page/{n}'", $frontController);
        $this->assertStringContainsString('.pagination', $siteCss);

        $zip->close();
        if (is_file($package['path'])) {
            unlink($package['path']);
        }
    }

    public function test_rewrite_mode_dynamic_rendering_pagination(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $port = $this->freeTcpPort();
        $baseUrl = 'http://127.0.0.1:'.$port;
        $endpointUrl = $baseUrl.'/blog';
        $extractPath = sys_get_temp_dir().'/geoflow-pagination-rewrite-'.uniqid();

        $channel = DistributionChannel::query()->create([
            'name' => 'Rewrite Pagination Blog',
            'domain' => '127.0.0.1',
            'endpoint_url' => $endpointUrl,
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'rewrite',
            'template_key' => 'default',
            'site_settings' => [
                'site_name' => 'Rewrite Blog',
                'per_page' => 2,
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_rewrite',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_rewrite_secret'),
            'status' => 'active',
            'scopes' => ['frontend.capabilities'],
        ]);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_rewrite', 'gfsec_rewrite_secret');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));
        $this->assertTrue($zip->extractTo($extractPath));
        $zip->close();

        // Write site-settings.json with per_page = 2
        file_put_contents($extractPath.'/storage/site-settings.json', json_encode([
            'site_name' => 'Rewrite Blog',
            'per_page' => 2,
        ], JSON_UNESCAPED_UNICODE));

        // Write 5 articles with distinct published dates (newest to oldest)
        $articlesDir = $extractPath.'/storage/articles';
        $this->writeArticleJson($articlesDir, 'article-5-newest', 'Article 5 Newest', '2026-01-05 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-4', 'Article 4', '2026-01-04 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-3', 'Article 3', '2026-01-03 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-2', 'Article 2', '2026-01-02 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-1-oldest', 'Article 1 Oldest', '2026-01-01 10:00:00');

        // Create router script for php -S
        file_put_contents($extractPath.'/router.php', <<<'PHP'
<?php
require __DIR__ . '/public/index.php';
PHP
        );

        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, 'router.php'], $extractPath);
        $server->start();
        config(['geoflow.outbound_private_targets' => ['127.0.0.1:'.$port]]);

        try {
            $this->waitForHttpServer($endpointUrl);

            // 1. GET /blog/
            $page1Response = Http::timeout(3)->get($endpointUrl.'/');
            $this->assertTrue($page1Response->successful());
            $page1Html = $page1Response->body();

            $this->assertStringContainsString('<link rel="canonical" href="'.$endpointUrl.'/">', $page1Html);
            $this->assertStringContainsString('article-5-newest', $page1Html);
            $this->assertStringContainsString('article-4', $page1Html);
            $this->assertStringNotContainsString('article-3', $page1Html);
            $this->assertStringNotContainsString('article-2', $page1Html);
            $this->assertStringNotContainsString('article-1-oldest', $page1Html);
            $this->assertStringContainsString('href="/blog/page/2"', $page1Html);
            $this->assertStringContainsString('rel="next"', $page1Html);
            $this->assertStringNotContainsString('rel="prev"', $page1Html);

            // 2. GET /blog/page/2
            $page2Response = Http::timeout(3)->get($endpointUrl.'/page/2');
            $this->assertTrue($page2Response->successful());
            $page2Html = $page2Response->body();

            $this->assertStringContainsString('<link rel="canonical" href="'.$endpointUrl.'/page/2">', $page2Html);
            $this->assertStringContainsString('rel="prev"', $page2Html);
            $this->assertStringContainsString('href="/blog/" rel="prev"', $page2Html);
            $this->assertStringContainsString('rel="next"', $page2Html);
            $this->assertStringContainsString('href="/blog/page/3" rel="next"', $page2Html);
            $this->assertStringContainsString('article-3', $page2Html);
            $this->assertStringContainsString('article-2', $page2Html);

            // 3. GET /blog/page/3
            $page3Response = Http::timeout(3)->get($endpointUrl.'/page/3');
            $this->assertTrue($page3Response->successful());
            $page3Html = $page3Response->body();

            $this->assertStringContainsString('<link rel="canonical" href="'.$endpointUrl.'/page/3">', $page3Html);
            $this->assertStringContainsString('article-1-oldest', $page3Html);
            $this->assertStringNotContainsString('rel="next"', $page3Html);
            $this->assertStringContainsString('rel="prev"', $page3Html);

            // 4. GET /blog/page/4 returns 404
            $page4Response = Http::timeout(3)->get($endpointUrl.'/page/4');
            $this->assertSame(404, $page4Response->status());
            $this->assertStringContainsString('页面不存在。', $page4Response->body());

            // 5. GET /blog/page/1 returns 301 with Location /blog/
            $page1Redirect = Http::withoutRedirecting()->timeout(3)->get($endpointUrl.'/page/1');
            $this->assertSame(301, $page1Redirect->status());
            $this->assertSame('/blog/', $page1Redirect->header('Location'));

            // 6. GET /blog/page/abc returns 404
            $pageAbcResponse = Http::timeout(3)->get($endpointUrl.'/page/abc');
            $this->assertSame(404, $pageAbcResponse->status());

            // 7. Union of article links over pages 1..3 == all 5 slugs
            $slugs = [];
            foreach ([$page1Html, $page2Html, $page3Html] as $html) {
                if (preg_match_all('#/article/([a-z0-9-]+)#', $html, $matches)) {
                    foreach ($matches[1] as $slug) {
                        $slugs[$slug] = true;
                    }
                }
            }
            $expectedSlugs = [
                'article-5-newest',
                'article-4',
                'article-3',
                'article-2',
                'article-1-oldest',
            ];
            sort($expectedSlugs);
            $actualSlugs = array_keys($slugs);
            sort($actualSlugs);
            $this->assertSame($expectedSlugs, $actualSlugs);
        } finally {
            $server->stop(0);
            if (is_file($package['path'])) {
                unlink($package['path']);
            }
            $this->removeDirectory($extractPath);
        }
    }

    public function test_static_mode_rebuild_and_prune_pagination(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $port = $this->freeTcpPort();
        $baseUrl = 'http://127.0.0.1:'.$port;
        $endpointUrl = $baseUrl.'/blog';
        $extractPath = sys_get_temp_dir().'/geoflow-pagination-static-'.uniqid();

        $channel = DistributionChannel::query()->create([
            'name' => 'Static Pagination Blog',
            'domain' => '127.0.0.1',
            'endpoint_url' => $endpointUrl,
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'static',
            'template_key' => 'default',
            'site_settings' => [
                'site_name' => 'Static Blog',
                'per_page' => 2,
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_static',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_static_secret'),
            'status' => 'active',
            'scopes' => ['frontend.capabilities'],
        ]);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_static', 'gfsec_static_secret');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));
        $this->assertTrue($zip->extractTo($extractPath));
        $zip->close();

        // Write site-settings.json with per_page = 2
        file_put_contents($extractPath.'/storage/site-settings.json', json_encode([
            'site_name' => 'Static Blog',
            'per_page' => 2,
        ], JSON_UNESCAPED_UNICODE));

        // Write 5 articles with distinct published dates
        $articlesDir = $extractPath.'/storage/articles';
        $this->writeArticleJson($articlesDir, 'art-5', 'Art 5', '2026-01-05 10:00:00');
        $this->writeArticleJson($articlesDir, 'art-4', 'Art 4', '2026-01-04 10:00:00');
        $this->writeArticleJson($articlesDir, 'art-3', 'Art 3', '2026-01-03 10:00:00');
        $this->writeArticleJson($articlesDir, 'art-2', 'Art 2', '2026-01-02 10:00:00');
        $this->writeArticleJson($articlesDir, 'art-1', 'Art 1', '2026-01-01 10:00:00');

        // Create runner script to execute rebuildStaticSite
        $runnerFile = $extractPath.'/static-runner.php';
        file_put_contents($runnerFile, <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'CLI-TEST';
$_SERVER['REQUEST_URI'] = '/__no_route__';
$config = require __DIR__ . '/config.php';
ob_start();
require __DIR__ . '/public/index.php';
ob_end_clean();
$result = rebuildStaticSite($config);
echo json_encode($result);
PHP
        );

        try {
            // First static build
            $proc = new Process([PHP_BINARY, $runnerFile], $extractPath);
            $proc->mustRun();
            $result = json_decode($proc->getOutput(), true);

            $this->assertIsArray($result);
            $this->assertTrue($result['enabled']);
            $this->assertSame(5, $result['articles']);
            $this->assertSame(3, $result['pages']);
            $this->assertSame(0, $result['pages_removed']);

            // Assert page files exist / do not exist
            $this->assertFileExists($extractPath.'/index.html');
            $this->assertFileExists($extractPath.'/page/2/index.html');
            $this->assertFileExists($extractPath.'/page/3/index.html');
            $this->assertDirectoryDoesNotExist($extractPath.'/page/4');
            $this->assertDirectoryDoesNotExist($extractPath.'/page/1');

            // Assert index.html canonical and href with static trailing slash
            $indexHtml = (string) file_get_contents($extractPath.'/index.html');
            $this->assertStringContainsString('<link rel="canonical" href="'.$endpointUrl.'/">', $indexHtml);
            $this->assertStringContainsString('href="/blog/page/2/"', $indexHtml);

            // Assert page/3/index.html links back with rel="prev" href "/blog/page/2/"
            $page3Html = (string) file_get_contents($extractPath.'/page/3/index.html');
            $this->assertStringContainsString('rel="prev"', $page3Html);
            $this->assertStringContainsString('href="/blog/page/2/" rel="prev"', $page3Html);

            // Delete 2 article files
            @unlink($articlesDir.'/art-1.json');
            @unlink($articlesDir.'/art-2.json');

            // Pre-create a stale <extract>/page/1/ dir
            mkdir($extractPath.'/page/1', 0755, true);
            file_put_contents($extractPath.'/page/1/index.html', 'stale');

            // Rebuild again
            $proc2 = new Process([PHP_BINARY, $runnerFile], $extractPath);
            $proc2->mustRun();
            $result2 = json_decode($proc2->getOutput(), true);

            $this->assertIsArray($result2);
            $this->assertTrue($result2['enabled']);
            $this->assertSame(3, $result2['articles']);
            $this->assertSame(2, $result2['pages']);
            $this->assertSame(2, $result2['pages_removed']);

            // Assert page/3 and page/1 were pruned and page/2 remains
            $this->assertDirectoryDoesNotExist($extractPath.'/page/3');
            $this->assertDirectoryDoesNotExist($extractPath.'/page/1');
            $this->assertFileExists($extractPath.'/page/2/index.html');
        } finally {
            if (is_file($package['path'])) {
                unlink($package['path']);
            }
            $this->removeDirectory($extractPath);
        }
    }

    public function test_apparel_and_fashion_themes_pagination(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        foreach (['apparel-sourcing-intelligence', 'fashion-insight'] as $templateKey) {
            $extractPath = sys_get_temp_dir().'/geoflow-theme-'.$templateKey.'-'.uniqid();
            $channel = DistributionChannel::query()->create([
                'name' => 'Theme Test '.$templateKey,
                'domain' => '127.0.0.1',
                'endpoint_url' => 'http://127.0.0.1:8080/blog',
                'channel_type' => 'geoflow_agent',
                'front_mode' => 'rewrite',
                'template_key' => $templateKey,
                'site_settings' => [
                    'site_name' => 'Theme '.$templateKey,
                    'per_page' => 2,
                    'active_theme' => $templateKey,
                ],
                'status' => 'active',
            ]);
            DistributionChannelSecret::query()->create([
                'distribution_channel_id' => (int) $channel->id,
                'key_id' => 'gfk_'.$templateKey,
                'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_'.$templateKey),
                'status' => 'active',
                'scopes' => ['frontend.capabilities'],
            ]);

            $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_'.$templateKey, 'gfsec_'.$templateKey);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($package['path']));
            $this->assertTrue($zip->extractTo($extractPath));
            $zip->close();

            // Write 5 articles
            $articlesDir = $extractPath.'/storage/articles';
            $this->writeArticleJson($articlesDir, 't-art-5', 'Title 5', '2026-01-05 10:00:00');
            $this->writeArticleJson($articlesDir, 't-art-4', 'Title 4', '2026-01-04 10:00:00');
            $this->writeArticleJson($articlesDir, 't-art-3', 'Title 3', '2026-01-03 10:00:00');
            $this->writeArticleJson($articlesDir, 't-art-2', 'Title 2', '2026-01-02 10:00:00');
            $this->writeArticleJson($articlesDir, 't-art-1', 'Title 1', '2026-01-01 10:00:00');

            $runnerFile = $extractPath.'/theme-runner.php';
            file_put_contents($runnerFile, <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'CLI-TEST';
$_SERVER['REQUEST_URI'] = '/__no_route__';
$config = require __DIR__ . '/config.php';
ob_start();
require __DIR__ . '/public/index.php';
ob_end_clean();

echo json_encode([
    'page1' => renderHomePageHtml($config, 1),
    'page2' => renderHomePageHtml($config, 2),
    'page99' => renderHomePageHtml($config, 99),
]);
PHP
            );

            try {
                $proc = new Process([PHP_BINARY, $runnerFile], $extractPath);
                $proc->mustRun();
                $rendered = json_decode($proc->getOutput(), true);

                $page1 = $rendered['page1'];
                $page2 = $rendered['page2'];
                $page99 = $rendered['page99'];

                $this->assertStringContainsString('class="pagination"', $page1);
                $this->assertStringContainsString('class="pagination"', $page2);
                $this->assertStringContainsString('页面不存在。', $page99);

                if ($templateKey === 'apparel-sourcing-intelligence') {
                    $this->assertStringContainsString('class="asi-lead"', $page1);
                    $this->assertStringNotContainsString('class="asi-lead"', $page2);
                    $this->assertStringContainsString('Latest Intelligence - Page 2', $page2);
                } else {
                    $this->assertStringContainsString('Vanguard Choice', $page1);
                    $this->assertStringNotContainsString('Vanguard Choice', $page2);
                    $this->assertStringContainsString('首页 - 第 2 页', $page2);
                }
            } finally {
                if (is_file($package['path'])) {
                    unlink($package['path']);
                }
                $this->removeDirectory($extractPath);
            }
        }
    }

    private function writeArticleJson(string $dir, string $slug, string $title, string $publishedAt): void
    {
        $data = [
            'article' => [
                'slug' => $slug,
                'title' => $title,
                'published_at' => $publishedAt,
                'excerpt' => 'Summary of '.$title,
                'content' => 'Content of '.$title,
                'content_html' => '<p>Content of '.$title.'</p>',
            ],
        ];
        file_put_contents($dir.'/'.$slug.'.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function freeTcpPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, $errstr);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr(strrchr($address, ':'), 1);
    }

    private function waitForHttpServer(string $baseUrl): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                $response = Http::timeout(1)->get(rtrim($baseUrl, '/').'/');
                if ($response->status() < 500) {
                    return;
                }
            } catch (\Throwable) {
                usleep(100000);
            }
        }

        $this->fail('PHP runtime target server did not start.');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
        }

        rmdir($path);
    }
}
