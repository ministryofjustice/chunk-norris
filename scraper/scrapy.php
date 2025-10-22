<?php

/**
 * WordPress Multisite Content Scraper
 * Fetches all posts and pages from WP REST API for multiple sites
 */

// ============================================
// CONFIGURATION - Edit these values
// ============================================
$BASE_URL = 'https://hale.docker';
$SITE_IDS = [1, 2, 3]; // Add your site IDs here
$OUTPUT_DIR = 'wordpress_content';
// ============================================

class WordPressMultisiteScraper
{
    private $baseUrl;
    private $outputDir;

    public function __construct($baseUrl, $outputDir = 'wordpress_content')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->outputDir = $outputDir;
    }

    /**
     * Strip HTML tags and clean up text
     */
    private function stripHtml($html)
    {
        // Decode HTML entities
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove HTML tags
        $text = strip_tags($text);

        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Fetch all items from a paginated endpoint
     */
    private function fetchAllItems($endpoint, $siteId)
    {
        $items = [];
        $page = 1;
        $perPage = 100;

        while (true) {
            // Build URL for multisite
            if ($siteId === 1) {
                // Main site
                $url = "{$this->baseUrl}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}&page={$page}";
            } else {
                // Subsite
                $url = "{$this->baseUrl}/site-{$siteId}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}&page={$page}";
            }

            echo "Fetching {$endpoint} page {$page} from site {$siteId}...\n";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local development
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // For local development

            $response = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                echo "Error: " . curl_error($ch) . "\n";
                curl_close($ch);
                break;
            }

            curl_close($ch);

            // Parse headers and body
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);

            if ($httpCode !== 200) {
                echo "HTTP Error {$httpCode} for site {$siteId}\n";
                if ($httpCode === 404) {
                    echo "Site {$siteId} may not exist or REST API is not accessible\n";
                }
                break;
            }

            $data = json_decode($body, true);

            if (empty($data)) {
                break;
            }

            $items = array_merge($items, $data);
            echo "Fetched " . count($data) . " {$endpoint}\n";

            // Check for total pages in headers
            preg_match('/X-WP-TotalPages: (\d+)/', $headers, $matches);
            $totalPages = isset($matches[1]) ? (int)$matches[1] : $page;

            if ($page >= $totalPages) {
                break;
            }

            $page++;
            usleep(500000); // 0.5 second delay
        }

        return $items;
    }

    /**
     * Save content to text files
     */
    private function saveContent($items, $contentType, $siteId)
    {
        $typeDir = "{$this->outputDir}/site-{$siteId}/{$contentType}";

        if (!is_dir($typeDir)) {
            mkdir($typeDir, 0755, true);
        }

        foreach ($items as $item) {
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

            // Create filename
            $slug = isset($item['slug']) ? $item['slug'] : "{$contentType}-{$item['id']}";
            $slug = preg_replace('/[^a-z0-9-_]/', '', strtolower($slug));
            $filename = "{$slug}.txt";

            // Combine content
            $fullText = "Site ID: {$siteId}\n";
            $fullText .= "Title: {$title}\n\n";

            if (!empty($excerpt)) {
                $fullText .= "Excerpt: {$excerpt}\n\n";
            }

            $fullText .= "Content:\n{$content}";

            // Save to file
            $filePath = "{$typeDir}/{$filename}";
            file_put_contents($filePath, $fullText);

            echo "Saved: {$filePath}\n";
        }
    }

    /**
     * Scrape a single site
     */
    private function scrapeSite($siteId)
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "Processing Site ID: {$siteId}\n";
        echo str_repeat("=", 60) . "\n\n";

        // Fetch posts
        echo "=== Fetching Posts from Site {$siteId} ===\n";
        $posts = $this->fetchAllItems('posts', $siteId);
        echo "Total posts fetched: " . count($posts) . "\n\n";

        // Fetch pages
        echo "=== Fetching Pages from Site {$siteId} ===\n";
        $pages = $this->fetchAllItems('pages', $siteId);
        echo "Total pages fetched: " . count($pages) . "\n\n";

        // Save content
        if (!empty($posts)) {
            echo "=== Saving Posts from Site {$siteId} ===\n";
            $this->saveContent($posts, 'posts', $siteId);
        }

        if (!empty($pages)) {
            echo "\n=== Saving Pages from Site {$siteId} ===\n";
            $this->saveContent($pages, 'pages', $siteId);
        }

        return [
            'posts' => count($posts),
            'pages' => count($pages)
        ];
    }

    /**
     * Run the scraper for multiple sites
     */
    public function run($siteIds)
    {
        echo "Fetching content from: {$this->baseUrl}\n";
        echo "Output directory: {$this->outputDir}\n";
        echo "Target sites: " . implode(', ', $siteIds) . "\n";

        // Create output directory
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }

        $summary = [];

        foreach ($siteIds as $siteId) {
            $summary[$siteId] = $this->scrapeSite($siteId);
        }

        // Print summary
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "SCRAPING COMPLETE - SUMMARY\n";
        echo str_repeat("=", 60) . "\n";

        $totalPosts = 0;
        $totalPages = 0;

        foreach ($summary as $siteId => $counts) {
            echo "Site {$siteId}: {$counts['posts']} posts, {$counts['pages']} pages\n";
            $totalPosts += $counts['posts'];
            $totalPages += $counts['pages'];
        }

        echo "\nTotal: {$totalPosts} posts and {$totalPages} pages across " . count($siteIds) . " sites\n";
        echo "Saved to: {$this->outputDir}\n";
    }
}

// Main execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Run scraper
$scraper = new WordPressMultisiteScraper($BASE_URL, $OUTPUT_DIR);
$scraper->run($SITE_IDS);
