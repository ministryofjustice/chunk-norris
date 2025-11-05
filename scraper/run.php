<?php

/**
 * WordPress Multisite Content Scraper with S3 Upload
 * Fetches all posts and pages from WP REST API and uploads to S3
 */

require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// ============================================
// CONFIGURATION - Edit these values
// ============================================
$BASE_URL   = getenv('PUBLIC_BASE_URL') ?: 'https://hale.docker';
$SITE_IDS   = getenv('SITE_IDS') ? array_map('intval', explode(',', getenv('SITE_IDS'))) : [5];
$OUTPUT_DIR = getenv('OUTPUT_DIR') ?: 'wordpress_content';
$ENV        = getenv('ENV') ?: 'PROD';

// S3 Configuration
$S3_BUCKET  = getenv('S3_BUCKET') ?: 'my-wordpress-content-bucket';
$S3_REGION  = getenv('S3_REGION') ?: 'eu-west-2';
$S3_PREFIX  = getenv('S3_PREFIX') ?: 'wordpress-scrapes/'; // Optional prefix/folder in bucket

// ============================================

class WordPressMultisiteScraper
{
    private $baseUrl;
    private $outputDir;
    private $env;
    private $s3Client;
    private $s3Bucket;
    private $s3Prefix;

    public function __construct($baseUrl, $outputDir = 'wordpress_content', $env = 'DEV', $s3Config = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->outputDir = $outputDir;
        $this->env = $env;

        // Initialize S3 client if config provided
        if ($s3Config) {
            $this->s3Bucket = $s3Config['bucket'];
            $this->s3Prefix = rtrim($s3Config['prefix'], '/') . '/';

            // When running in K8s with IRSA, credentials are automatically provided
            // via environment variables or instance metadata
            $this->s3Client = new S3Client([
                'version' => 'latest',
                'region'  => $s3Config['region'],
                // Credentials automatically loaded from:
                // - Environment variables (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY)
                // - IAM role attached to service account (IRSA)
                // - EKS Pod Identity
            ]);

            echo "S3 upload enabled - Bucket: {$this->s3Bucket}, Region: {$s3Config['region']}\n";
        }
    }

    /**
     * Upload file to S3
     */
    private function uploadToS3($content, $s3Key)
    {
        if (!$this->s3Client) {
            echo "S3 client not initialized, skipping upload\n";
            return false;
        }

        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->s3Bucket,
                'Key'    => $s3Key,
                'Body'   => $content,
                'ContentType' => 'text/plain',
                // Optional: Add metadata
                'Metadata' => [
                    'uploaded-by' => 'wordpress-scraper',
                    'timestamp' => date('c'),
                ],
            ]);

            echo "✓ Uploaded to S3: s3://{$this->s3Bucket}/{$s3Key}\n";
            return true;

        } catch (AwsException $e) {
            echo "✗ S3 Upload Error: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Strip HTML tags and clean up text
     */
    private function stripHtml($html)
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        return $text;
    }

    /**
     * Fetch all items from a paginated endpoint
     */
    private function fetchAllItems($endpoint, $siteId, $baseURL = '')
    {
        $items = [];
        $page = 1;
        $perPage = 100;

        if (!empty($baseURL)) {
            $apiURL = "{$baseURL}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}";
        } else {
            if ($siteId === 1) {
                $apiURL = "{$this->baseUrl}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}";
            } else {
                $apiURL = "{$this->baseUrl}/site-{$siteId}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}";
            }
        }

        $currentPage = "&page={$page}";
        echo "Fetching {$endpoint} page {$page} from site {$siteId}...\n";

        $apiResponse = $this->fetchFromApi($apiURL . $currentPage);
        $items = array_merge($items, $apiResponse['data']);
        echo "Fetched " . count($apiResponse['data']) . " {$endpoint}\n";

        preg_match('/X-WP-TotalPages: (\d+)/i', $apiResponse['headers'], $matches);
        $totalPages = isset($matches[1]) ? (int)$matches[1] : $page;

        echo "Endpoint Pages - $totalPages pages found for {$endpoint} endpoint from site {$siteId}...\n";

        if ($totalPages > 1) {
            for ($page = 2; $page <= $totalPages; $page++) {
                echo "Fetching {$endpoint} endpoint page {$page} from site {$siteId}...\n";
                $currentPage = "&page={$page}";
                $apiResponse = $this->fetchFromApi($apiURL . $currentPage);
                $items = array_merge($items, $apiResponse['data']);
            }
        }

        return $items;
    }

    /**
     * Save raw content to S3 (or local if S3 not configured)
     */
    private function saveRawContent($item, $contentType, $siteId)
    {
        $rawContent = '';

        $rawContent .= isset($item['title']['rendered'])
                ? '<h1>' . $item['title']['rendered'] . '</h1>'
                : 'Untitled';

        $rawContent .= isset($item['content']['rendered'])
                ? $item['content']['rendered']
                : '';

        $slug = isset($item['slug']) ? $item['slug'] : "{$contentType}-{$item['id']}";
        $slug = preg_replace('/[^a-z0-9-_]/', '', strtolower($slug));
        $filename = "{$slug}.txt";

        // Upload to S3 or save locally
        if ($this->s3Client) {
            $s3Key = $this->s3Prefix . "site-{$siteId}/raw/{$contentType}/{$filename}";
            $this->uploadToS3($rawContent, $s3Key);
        } else {
            // Fallback to local storage
            $typeDir = "{$this->outputDir}/site-{$siteId}/raw/{$contentType}";
            if (!is_dir($typeDir)) {
                mkdir($typeDir, 0755, true);
            }
            $filePath = "{$typeDir}/{$filename}";
            file_put_contents($filePath, $rawContent);
            echo "Saved locally: {$filePath}\n";
        }
    }

    /**
     * Save content to S3 (or local if S3 not configured)
     */
    private function saveContent($items, $contentType, $siteId, $siteTaxonomies)
    {
        $contentTypeTaxonomies = [];

        foreach ($siteTaxonomies as $taxonomy) {
            if (in_array($contentType, $taxonomy['types'])) {
                $contentTypeTaxonomies[] = $taxonomy;
            }
        }

        foreach ($items as $item) {
            $this->saveRawContent($item, $contentType, $siteId);

            // Extract content
            $title = isset($item['title']['rendered'])
                ? $this->stripHtml($item['title']['rendered'])
                : 'Untitled';

            $content = isset($item['content']['rendered'])
                ? $this->stripHtml($item['content']['rendered'])
                : '';

            $excerpt = isset($item['excerpt']['rendered'])
                ? $this->stripHtml($item['excerpt']['rendered'])
                : '';

            $slug = isset($item['slug']) ? $item['slug'] : "{$contentType}-{$item['id']}";
            $slug = preg_replace('/[^a-z0-9-_]/', '', strtolower($slug));
            $filename = "{$slug}.txt";

            // Combine content
            $fullText = "Site ID: {$siteId}\n";
            $fullText .= "Title: {$title}\n\n";

            foreach ($contentTypeTaxonomies as $taxonomy) {
                if (array_key_exists($taxonomy['slug'], $item) && !empty($item[$taxonomy['slug']])) {
                    $term_names = [];
                    $term_ids = $item[$taxonomy['slug']];

                    foreach ($term_ids as $term_id) {
                        if (array_key_exists($term_id, $taxonomy['terms'])) {
                            $term_names[] = $taxonomy['terms'][$term_id]['name'];
                        }
                    }

                    if (!empty($term_names)) {
                        $fullText .= $taxonomy['name'] . ": \n\n";
                        $fullText .= implode(", ", $term_names) . " \n\n";
                    }
                }
            }

            if (!empty($excerpt)) {
                $fullText .= "Excerpt: {$excerpt}\n\n";
            }

            if (array_key_exists('post_meta', $item) && !empty($item['post_meta'])) {
                if (array_key_exists('summary', $item['post_meta']) && !empty($item['post_meta']['summary'])) {
                    $fullText .= "Summary: {$item['post_meta']['summary']}\n\n";
                }
            }

            $fullText .= "Content:\n{$content}";

            // Upload to S3 or save locally
            if ($this->s3Client) {
                $s3Key = $this->s3Prefix . "site-{$siteId}/clean/{$contentType}/{$filename}";
                $this->uploadToS3($fullText, $s3Key);
            } else {
                // Fallback to local storage
                $typeDir = "{$this->outputDir}/site-{$siteId}/clean/{$contentType}";
                if (!is_dir($typeDir)) {
                    mkdir($typeDir, 0755, true);
                }
                $filePath = "{$typeDir}/{$filename}";
                file_put_contents($filePath, $fullText);
                echo "Saved locally: {$filePath}\n";
            }
        }
    }

    /**
     * Scrape a single site
     */
    private function scrapeSite($siteId, $baseURL = '')
    {
        $scrapeSummary = [];

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "Processing Site ID: {$siteId}\n";
        echo str_repeat("=", 60) . "\n\n";

        $sitePostTypes = $this->getSitePostTypes($baseURL);
        $siteTaxonomies = $this->getSiteTaxonomies($baseURL);

        foreach ($sitePostTypes as $postType) {
            $postTypeName = $postType['name'];
            echo "=== Fetching {$postTypeName} from Site {$siteId} ===\n";

            $items = $this->fetchAllItems($postType['rest_base'], $siteId, $baseURL);

            echo "Total {$postTypeName} fetched: " . count($items) . "\n\n";

            if (!empty($items)) {
                echo "=== Saving {$postTypeName} from Site {$siteId} ===\n";
                $this->saveContent($items, $postType['slug'], $siteId, $siteTaxonomies);
            }

            $scrapeSummary[] = [
                'postTypeName' => $postType['name'],
                'itemCount' => count($items)
            ];
        }

        return $scrapeSummary;
    }

    /**
     * Run the scraper for multiple sites
     */
    public function run($siteIds)
    {
        echo "Fetching content from: {$this->baseUrl}\n";
        echo "Output directory: {$this->outputDir}\n";
        echo "Target sites: " . implode(', ', $siteIds) . "\n";

        $summary = [];

        if ($this->env == 'PROD') {
            $site_list = $this->getSiteList();

            foreach ($site_list as $site) {
                $siteId = (int) $site["blogID"];

                if (in_array($siteId, $siteIds)) {
                    $summary[$siteId] = $this->scrapeSite($siteId, $site["url"]);
                }
            }
        } else {
            foreach ($siteIds as $siteId) {
                $summary[$siteId] = $this->scrapeSite($siteId);
            }
        }

        // Print summary
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "SCRAPING COMPLETE - SUMMARY\n";
        echo str_repeat("=", 60) . "\n";

        foreach ($summary as $siteId => $siteSummary) {
            $summaryText = "Site {$siteId}: ";
            $count = 0;
            foreach ($siteSummary as $postType) {
                if ($count > 0) {
                    $summaryText .= ", ";
                }
                $summaryText .= $postType['itemCount'] . " " . $postType['postTypeName'];
                $count++;
            }
            echo $summaryText . "\n";
        }

        if ($this->s3Client) {
            echo "\nFiles uploaded to: s3://{$this->s3Bucket}/{$this->s3Prefix}\n";
        } else {
            echo "\nFiles saved locally to: {$this->outputDir}\n";
        }
    }

    private function fetchFromApi($apiURL)
    {
        $apiResponse = ['data' => [], 'headers' => ''];
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $apiURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            echo "cURL Error Number: " . curl_errno($ch) . "\n";
            echo "cURL Error Message: " . curl_error($ch) . "\n";
            $data = null;
        } else {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

            if ($httpCode === 200) {
                $headers = substr($response, 0, $headerSize);
                $body = substr($response, $headerSize);
                $apiResponse['headers'] = $headers;
                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo "JSON Decode Error: " . json_last_error_msg() . "\n";
                    $data = null;
                }
            } else {
                echo "HTTP Error: Received status code $httpCode\n";
                $data = null;
            }
        }

        curl_close($ch);

        if ($data !== null) {
            $apiResponse['data'] = $data;
        }

        return $apiResponse;
    }

    private function getSiteTaxonomies($baseURL = '')
    {
        $siteTaxonomies = [];
        $apiURL = $baseURL . '/wp-json/wp/v2/taxonomies';
        $apiResponse = $this->fetchFromApi($apiURL);
        $fetchedTaxonomies = $apiResponse['data'];

        $excludedTaxonomies = ['nav_menu', 'wp_pattern_category'];

        foreach ($fetchedTaxonomies as $taxonomy) {
            if (!in_array($taxonomy['slug'], $excludedTaxonomies)) {
                $terms = [];
                $apiURL = $baseURL . '/wp-json/wp/v2/' . $taxonomy['rest_base'];
                $apiResponse = $this->fetchFromApi($apiURL);
                $fetchedTerms = $apiResponse['data'];

                foreach ($fetchedTerms as $term) {
                    $terms[$term['id']] = $term;
                }

                $taxonomy['terms'] = $terms;
                $siteTaxonomies[] = $taxonomy;
            }
        }

        return $siteTaxonomies;
    }

    private function getSitePostTypes($baseURL = '')
    {
        $postTypes = [];
        $apiURL = $baseURL . '/wp-json/wp/v2/types';
        $apiResponse = $this->fetchFromApi($apiURL);
        $fetchedPostTypes = $apiResponse['data'];

        $excludedPostTypes = [
            'attachment', 'nav_menu_item', 'wp_block', 'wp_template',
            'wp_template_part', 'wp_global_styles', 'wp_navigation',
            'wp_font_family', 'wp_font_face'
        ];

        foreach ($fetchedPostTypes as $postType) {
            if (!in_array($postType['slug'], $excludedPostTypes)) {
                $postTypes[] = $postType;
            }
        }

        return $postTypes;
    }

    private function getSiteList()
    {
        $apiURL = 'https://websitebuilder.service.justice.gov.uk/wp-json/hc-rest/v1/sites/domain';
        $apiResponse = $this->fetchFromApi($apiURL);
        return $apiResponse['data'];
    }
}

// Main execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Configure S3 (set to null to disable S3 and use local storage)
$s3Config = [
    'bucket' => $S3_BUCKET,
    'region' => $S3_REGION,
    'prefix' => $S3_PREFIX,
];

// Run scraper
$scraper = new WordPressMultisiteScraper($BASE_URL, $OUTPUT_DIR, $ENV, $s3Config);
$scraper->run($SITE_IDS);
