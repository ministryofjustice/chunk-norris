<?php

/**
 * WordPress Multisite Content Scraper
 * Fetches all posts and pages from WP REST API for multiple sites
 */

 /*

47 = https://legalaidlearning.justice.gov.uk
5 = https://ccrc.gov.uk
52 = https://pecs-contract-guide.service.justice.gov.uk
// messy
14 = https://ppo.gov.uk
54 = https://lawcom.gov.uk

*/

// ============================================
// CONFIGURATION - Edit these values
// ============================================
$BASE_URL = 'https://hale.docker';
$SITE_IDS = [5, 47, 52]; // Add your site IDs here
$OUTPUT_DIR = 'wordpress_content';
$ENV = 'PROD';
// ============================================

class WordPressMultisiteScraper
{
    private $baseUrl;
    private $outputDir;
    private $env;

    public function __construct($baseUrl, $outputDir = 'wordpress_content', $env = 'DEV')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->outputDir = $outputDir;
        $this->env = $env;
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
    private function fetchAllItems($endpoint, $siteId, $baseURL = '')
    {
        $items = [];
        $page = 1;
        $perPage = 100;

        if(!empty($baseURL)){
            $url = "{$baseURL}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}&page={$page}";
        }
        else {
            // Build URL for multisite
            if ($siteId === 1) {
                // Main site
                $url = "{$this->baseUrl}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}&page={$page}";
            } else {
                // Subsite
                $url = "{$this->baseUrl}/site-{$siteId}/wp-json/wp/v2/{$endpoint}?per_page={$perPage}&page={$page}";
            }
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
                return $items;
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
                return $items;
            }

        
            $data = json_decode($body, true);

            if (empty($data)) {
                return $items;
            }

            $items = array_merge($items, $data);
            echo "Fetched " . count($data) . " {$endpoint}\n";

            // Check for total pages in headers
            //preg_match('/X-WP-TotalPages: (\d+)/', $headers, $matches);
            //$totalPages = isset($matches[1]) ? (int)$matches[1] : $page;


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
    private function scrapeSite($siteId, $baseURL = '')
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "Processing Site ID: {$siteId}\n";
        echo str_repeat("=", 60) . "\n\n";

        // Fetch posts
        echo "=== Fetching Posts from Site {$siteId} ===\n";
        $posts = $this->fetchAllItems('posts', $siteId, $baseURL);
        echo "Total posts fetched: " . count($posts) . "\n\n";

        // Fetch pages
        echo "=== Fetching Pages from Site {$siteId} ===\n";
        $pages = $this->fetchAllItems('pages', $siteId, $baseURL);
        echo "Total pages fetched: " . count($pages) . "\n\n";

        /*
        // Save content
        if (!empty($posts)) {
            echo "=== Saving Posts from Site {$siteId} ===\n";
            $this->saveContent($posts, 'posts', $siteId);
        }
        */

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

        if($this->env == 'PROD'){
            $site_list = $this->getSiteList();

            foreach ($site_list as $site) {
              
                $siteId = (int) $site["blogID"];

               if(in_array($siteId, $siteIds)){
                $summary[$siteId] = $this->scrapeSite($siteId, $site["url"]);
               }
               
            }
        }
        else {
            //Local
            foreach ($siteIds as $siteId) {
                $summary[$siteId] = $this->scrapeSite($siteId);
            }
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

    public function getSiteList()
    {

        /**
         * Definitive site list that have production domains.
         * This site list is pulled from our Prod site list API.
         *
         * The API contains a list of sites with their respective information.
         *
         * - 'blogID': The ID of the blog.
         * - 'domain': The domain of the site.
         * - 'slug': The unique given directory path of the site.
         */

        // Initialize a cURL session
        $ch = curl_init();

        // Set URL we want
        curl_setopt($ch, CURLOPT_URL, 'https://websitebuilder.service.justice.gov.uk/wp-json/hc-rest/v1/sites/domain');

        // Output as a string not to browser
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Set a timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // Follow redirects (if ever useful)
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        // Make sure to verify SSL certs
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        // Optional but useful additions
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);     // Timeout for connection phase only
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);           // Limit number of redirects

        // Execute response
        $response = curl_exec($ch);

        // Check for cURL errors
        // curl_errno() returns the error number (0 = no error)
        if (curl_errno($ch)) {
            echo "cURL Error Number: " . curl_errno($ch) . "\n";
            echo "cURL Error Message: " . curl_error($ch) . "\n";
            $data = null;
        } else {
            // Get HTTP response code
            // Server responded with 200 OK, 404 Not Found, etc.
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // Useful debug info about the request
            #$info = curl_getinfo($ch);
            #echo "HTTP Code: " . $httpCode . "\n";
            #echo "Content Type: " . $info['content_type'] . "\n";
            #echo "Total Time: " . $info['total_time'] . " seconds\n";
            #echo "Download Size: " . $info['size_download'] . " bytes\n";

            if ($httpCode === 200) {
                // Process successful response
                $data = json_decode($response, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo "JSON Decode Error: " . json_last_error_msg() . "\n";
                    $data = null;
                }
            } else {
                echo "HTTP Error: Received status code $httpCode\n";
                $data = null;
            }
        }

        // Always close the cURL handle to free up resources
        curl_close($ch);

        if ($data !== null) {
            return $data;
        }
        else {
            return [];
        }
    }
}

// Main execution
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Run scraper
$scraper = new WordPressMultisiteScraper($BASE_URL, $OUTPUT_DIR, $ENV);
$scraper->run($SITE_IDS);
