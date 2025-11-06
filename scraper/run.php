<?php

/**
 * WordPress Multisite Content Scraper with S3 Upload
 * Fetches all posts and pages from WP REST API and uploads to S3
 * Fully debugged to show API responses, item counts, and content lengths.
 */

// --------------------------------------------
// ENVIRONMENT
// --------------------------------------------
$ENV = getenv('ENV') ?: 'prod';
$ENV = strtolower(trim($ENV));

if ($ENV !== 'local') {
    require 'vendor/autoload.php';
}

// --------------------------------------------
// CONFIGURATION
// --------------------------------------------
$BASE_URL   = getenv('PUBLIC_BASE_URL') ?: 'https://hale.docker';
$SITE_IDS   = getenv('SITE_IDS') ? array_map('intval', explode(',', getenv('SITE_IDS'))) : [1];
$OUTPUT_DIR = getenv('OUTPUT_DIR') ?: 'wordpress-content';

$S3_BUCKET  = getenv('S3_BUCKET') ?: '';
$S3_REGION  = getenv('S3_REGION') ?: 'eu-west-2';
$S3_PREFIX  = getenv('S3_PREFIX') ?: 'wordpress-content/';


// ============================================
// SCRAPER CLASS
// ============================================
class WordPressMultisiteScraper
{
    private $baseUrl;
    private $outputDir;
    private $env;
    private $s3Client = null;
    private $s3Bucket;
    private $s3Prefix;
    private $uploadFailures = [];

    public function __construct($baseUrl, $outputDir, $env, $s3Config = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->outputDir = $outputDir;
        $this->env = strtolower(trim($env));

        echo "Environment: {$this->env}\n";

        if ($this->env !== 'local' && $s3Config && !empty($s3Config['bucket'])) {
            $this->s3Bucket = $s3Config['bucket'];
            $this->s3Prefix = rtrim($s3Config['prefix'], '/') . '/';

            try {
                $this->s3Client = new \Aws\S3\S3Client([
                    'version' => 'latest',
                    'region'  => $s3Config['region'],
                ]);

                echo "✓ S3 upload enabled\n";
                echo "  Bucket: {$this->s3Bucket}\n";
                echo "  Region: {$s3Config['region']}\n";
                echo "  Prefix: {$this->s3Prefix}\n";

                echo "  Testing S3 connectivity...\n";
                try {
                    $this->s3Client->headBucket(['Bucket' => $this->s3Bucket]);
                    echo "  ✓ S3 bucket accessible\n";
                } catch (Exception $e) {
                    echo "  ✗ WARNING: Cannot access S3 bucket: " . $e->getMessage() . "\n";
                }

            } catch (Exception $e) {
                echo "✗ Failed to initialize S3 client: " . $e->getMessage() . "\n";
                $this->s3Client = null;
            }
        } else {
            echo "✓ Local mode - saving files to disk\n";
            echo "  Output directory: {$this->outputDir}\n";
            if (!is_dir($this->outputDir)) {
                mkdir($this->outputDir, 0755, true);
            }
        }
    }

    private function uploadToS3($content, $s3Key)
    {
        if (!$this->s3Client) {
            return false;
        }

        if (empty(trim($content))) {
            echo "Skipping upload for empty content: {$s3Key}\n";
            return false;
        }

        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->s3Bucket,
                'Key'    => $s3Key,
                'Body'   => $content,
                'ContentType' => 'text/plain',
                'Metadata' => [
                    'uploaded-by' => 'chunk-norris',
                    'timestamp' => date('c'),
                ],
            ]);

            $etag = $result->get('ETag') ?? 'unknown';
            echo "✓ Uploaded to S3: s3://{$this->s3Bucket}/{$s3Key} (ETag: {$etag})\n";
            return true;

        } catch (\Aws\S3\Exception\S3Exception $e) {
            echo "✗ S3 Exception: " . $e->getAwsErrorMessage() . "\n  Key: {$s3Key}\n";
            $this->uploadFailures[] = $s3Key;
            return false;

        } catch (Exception $e) {
            echo "✗ Upload Error: " . $e->getMessage() . "\n  Key: {$s3Key}\n";
            $this->uploadFailures[] = $s3Key;
            return false;
        }
    }

    private function stripHtml($html)
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function fetchFromApi($apiURL)
    {
        echo "Chunk: Fetching URL: $apiURL\n";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // set false temporarily if SSL fails
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if (curl_errno($ch)) {
            echo "cURL Error: " . curl_error($ch) . "\n";
            curl_close($ch);
            return ['data' => [], 'headers' => ''];
        }

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        if ($httpCode !== 200) {
            echo "HTTP Error: $httpCode for URL: $apiURL\n";
            curl_close($ch);
            return ['data' => [], 'headers' => $headers];
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "JSON Decode Error: " . json_last_error_msg() . "\n";
            $data = [];
        }

        curl_close($ch);
        return ['data' => $data, 'headers' => $headers];
    }

    private function fetchAllItems($endpoint, $siteId, $baseURL = '')
    {
        $items = [];
        $page = 1;
        $perPage = 100;
        $effectiveBaseURL = !empty($baseURL) ? $baseURL : $this->baseUrl;

        $apiURL = $siteId === 1
            ? "{$effectiveBaseURL}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}"
            : "{$effectiveBaseURL}/site-{$siteId}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}";

        do {
            $currentPage = "&page={$page}";
            $response = $this->fetchFromApi($apiURL . $currentPage);

            $fetched = $response['data'] ?? [];
            echo "Chunk: Fetched " . count($fetched) . " items from page {$page} of endpoint {$endpoint}\n";

            if (!empty($fetched)) {
                $items = array_merge($items, $fetched);
            } else {
                echo "Chunk: No items returned for page {$page}\n";
            }

            preg_match('/X-WP-TotalPages: (\d+)/i', $response['headers'], $matches);
            $totalPages = isset($matches[1]) ? (int)$matches[1] : $page;

            $page++;
        } while ($page <= $totalPages);

        echo "Chunk: Total items fetched for {$endpoint}: " . count($items) . "\n";
        return $items;
    }

    private function saveRawContent($item, $contentType, $siteId)
    {
        $rawContent = '';
        $rawContent .= $item['title']['rendered'] ?? 'Untitled';
        $rawContent .= "\n";
        $rawContent .= $item['content']['rendered'] ?? '';

        $slug = strtolower(preg_replace('/[^a-z0-9-_]/', '', $item['slug'] ?? "{$contentType}-{$item['id']}"));
        $filename = "{$slug}.txt";

        if ($this->s3Client) {
            $s3Key = $this->s3Prefix . "site-{$siteId}/raw/{$contentType}/{$filename}";
            echo "Chunk: Uploading raw content of length " . strlen($rawContent) . " to S3 key: {$s3Key}\n";
            $this->uploadToS3($rawContent, $s3Key);
        }
    }

    private function saveContent($items, $contentType, $siteId, $siteTaxonomies)
    {
        foreach ($items as $item) {
            $this->saveRawContent($item, $contentType, $siteId);

            $title = $this->stripHtml($item['title']['rendered'] ?? '');
            $content = $this->stripHtml($item['content']['rendered'] ?? '');
            $excerpt = $this->stripHtml($item['excerpt']['rendered'] ?? '');

            $slug = strtolower(preg_replace('/[^a-z0-9-_]/', '', $item['slug'] ?? "{$contentType}-{$item['id']}"));
            $filename = "{$slug}.txt";

            $fullText = "Site ID: {$siteId}\nTitle: {$title}\n\nExcerpt: {$excerpt}\n\nContent:\n{$content}";
            if ($this->s3Client) {
                $s3Key = $this->s3Prefix . "site-{$siteId}/clean/{$contentType}/{$filename}";
                echo "Chunk: Uploading full content of length " . strlen($fullText) . " to S3 key: {$s3Key}\n";
                $this->uploadToS3($fullText, $s3Key);
            }
        }
    }

    private function scrapeSite($siteId, $baseURL = '')
    {
        echo "\n=== Processing Site ID: {$siteId} ===\n";

        $sitePostTypes = $this->getSitePostTypes($baseURL);
        $siteTaxonomies = $this->getSiteTaxonomies($baseURL);

        foreach ($sitePostTypes as $postType) {
            echo "--- Fetching {$postType['name']} ---\n";
            $items = $this->fetchAllItems($postType['rest_base'], $siteId, $baseURL);

            if (!empty($items)) {
                echo "--- Saving {$postType['name']} ---\n";
                $this->saveContent($items, $postType['slug'], $siteId, $siteTaxonomies);
            } else {
                echo "Chunk: No items to save for {$postType['slug']}\n";
            }
        }
    }

    public function run($siteIds)
    {
        echo "\n=== WordPress Content Scraper ===\n";
        echo "Base URL: {$this->baseUrl}\n";
        echo "Target sites: " . implode(', ', $siteIds) . "\n";

        foreach ($siteIds as $siteId) {
            $this->scrapeSite($siteId);
        }

        echo "\n✓ Scraping complete.\n";

        if ($this->s3Client) {
            echo "✓ Files uploaded to: s3://{$this->s3Bucket}/{$this->s3Prefix}\n";
            if (!empty($this->uploadFailures)) {
                echo "⚠️  Upload failures:\n";
                foreach ($this->uploadFailures as $key) {
                    echo "  - {$key}\n";
                }
            }
        }
    }

    private function getSitePostTypes($baseURL = '')
    {
        $apiURL = (!empty($baseURL) ? rtrim($baseURL, '/') : $this->baseUrl) . '/wp-json/wp/v2/types';
        $response = $this->fetchFromApi($apiURL);
        return array_filter($response['data'] ?? [], fn ($pt) => !in_array($pt['slug'], [
            'attachment','nav_menu_item','wp_block','wp_template',
            'wp_template_part','wp_global_styles','wp_navigation',
            'wp_font_family','wp_font_face'
        ]));
    }

    private function getSiteTaxonomies($baseURL = '')
    {
        $apiURL = (!empty($baseURL) ? rtrim($baseURL, '/') : $this->baseUrl) . '/wp-json/wp/v2/taxonomies';
        $response = $this->fetchFromApi($apiURL);
        return $response['data'] ?? [];
    }
}

// --------------------------------------------
// MAIN EXECUTION
// --------------------------------------------
if (php_sapi_name() !== 'cli') {
    die("Must run from CLI.\n");
}

$s3Config = ($ENV !== 'local') ? [
    'bucket' => $S3_BUCKET,
    'region' => $S3_REGION,
    'prefix' => $S3_PREFIX
] : null;

$scraper = new WordPressMultisiteScraper($BASE_URL, $OUTPUT_DIR, $ENV, $s3Config);
$scraper->run($SITE_IDS);
