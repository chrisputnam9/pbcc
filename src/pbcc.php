<?php
/**
 * pbcc
 */

Class Pbcc extends Console_Abstract
{
    const VERSION = '0.0.1';

    // Name of script and directory to store config
    const SHORTNAME = 'pbcc';

    /**
     * Callable Methods
     */
    protected static $METHODS = [
        'search',
        'get',
        'post',
        'delete',
    ];

    // Config Variables
    protected $__api_key = ["Basecamp Classic API key", "string"];
    public $api_key = "";

    protected $__api_url = ["Basecamp Classic API URL", "string"];
    public $api_url = "";

    protected $__api_user_agent = ["User agent string to identify API requests. %s placeholder for user e-mail", "string"];
    public $api_user_agent = "PBCC (gh:chrisputnam9/pbcc) in use by %s";

    protected $__api_user_email = ["User e-mail to fill into user agent string", "string"];
    public $api_user_email = "";

    protected $__api_cache = ["Whether to cache results"];
    public $api_cache = true;

    protected $__api_cache_lifetime = ["How long to cache results in seconds (if enabled)"];
    public $api_cache_lifetime = 86400; // Default: 24 hours

    // Update this to your update URL, or remove it to disable updates
	public $update_version_url = "";

    protected $___search = [
        "Search GET data from the Basecamp Classic API",
        ["Text to search for", "string"],
        ["Endpoint slug", "string"],
        ["Whether to output results"],
    ];
	public function search($endpoint, $query, $output=true)
    {
        $results = $this->get($endpoint, false);
        $xml = new SimpleXMLElement($results);

        $results = $xml->xpath("/*/*/*[contains(., '$query')]/..");

        foreach ($results as $result)
        {
            $name = "";
            if (isset($result->name)) $name = $result->name;
            if (isset($result->content)) $name = $result->content;
            $name = strip_tags($name);
            if (strlen($name) > 100)
            {
                $name = substr($name, 0, 97) . '...';
            }
            echo "(" . $result->id . ") $name\n";
        }
    }

    protected $___get = [
        "GET data from the Basecamp Classic API",
        ["Endpoint slug", "string"],
        ["Whether to output results"],
    ];
	public function get($endpoint, $output=true)
    {
        // Clean up endpoint
        $endpoint = trim($endpoint, " \t\n\r\0\x0B/");
        $endpoint = preg_replace("~\.xml$~", "", $endpoint);
        $endpoint = $endpoint . '.xml';

        // Check for valid cached result if cache is enabled
        $cache_file = false;
        $results = "";
        if ($this->api_cache)
        {
            $this->log("Cache is enabled - checking...");
            $cache_file = $this->getAPICacheFilepath($endpoint);

            if (is_file($cache_file))
            {
                $this->log("Cache file exists ($cache_file) - checking age");
                $cache_modified = filemtime($cache_file);
                $now = time();
                $cache_age = $now - $cache_modified;
                if ($cache_age < $this->api_cache_lifetime)
                {
                    $this->log("New enough - reading from cache file ($cache_file)");
                    $results = file_get_contents($cache_file);
                    if ($results === false)
                    {
                        $this->warn("Failed to read cache file ($cache_file) - possible permissions issue");
                    }
                }
            }
        }

        if (empty($results))
        {
            $this->log("Running fresh API request");

            // Get API curl object for endpoint
            $ch = $this->getAPICurl($endpoint);

            // Execute and check results, then close curl
            $results = $this->runAPICurl($ch);

            // Cache results if enabled
            if ($this->api_cache)
            {
                $cache_dir = dirname($cache_file);
                if (!is_dir($cache_dir))
                    mkdir($cache_dir, 0755, true);

                $cache_file = $this->getAPICacheFilepath($endpoint);
                $written = file_put_contents($cache_file, $results);
                if ($written === false)
                {
                    $this->warn("Failed to write to cache file ($cache_file) - possible permissions issue");
                }
            }
        }

        if ($output)
        {
            $this->output($results);
        }

        return $results;
    }

    protected $___post = [
        "POST data to the Basecamp Classic API",
        ["Endpoint slug", "string"],
        ["Body - main body to post - XML string expected in CLI", "string"],
        ["Whether to output results"],
    ];
	public function post($endpoint, $body="", $output=true)
    {
        // Get API curl object for endpoint
        $ch = $this->getAPICurl($endpoint);

        // Prepare data
        if (is_object($body) and $body instanceof SimpleXMLElement)
        {
            $body = "" . $body;
        }
        elseif (!is_string($body))
        {
            $this->error("Invalid body data type - must be string or SimpleXMLElement");
        }

        // Modify for posting data
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
        ]);

        // Execute and check results, then close curl
        $results = $this->runAPICurl($ch);

        if ($output)
        {
            $this->output($results);
        }

        return $results;
    }

    protected $___delete = [
        "DELETE data from the Basecamp Classic API",
        ["Endpoint slug", "string"],
        ["Whether to output results"],
    ];
	public function delete($endpoint, $output=true)
    {
        // Get API curl object for endpoint
        $ch = $this->getAPICurl($endpoint);

        // Modify for deleting data
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
        ]);

        // Execute and check results, then close curl
        $results = $this->runAPICurl($ch);

        if ($output)
        {
            $this->output($results);
        }

        return $results;
    }

    /**
     * Get caching file path for given request
     */
    protected function getAPICacheFilepath($endpoint)
    {
        $config_dir = $this->getConfigDir();
        $cache_dir = $config_dir . DS . 'cache' . DS . 'bc-api';
        $endpoint = str_replace("/", DS, $endpoint);

        $cache_file = $cache_dir . DS . $endpoint;

        return $cache_file;
    }

    /**
     * Prep Curl object to hit BC API
     */
    protected function getAPICurl($endpoint)
    {
        $this->setupAPI();
        $ch = $this->getCurl($this->api_url . '/' . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_USERAGENT => sprintf($this->api_user_agent, $this->api_user_email),
            CURLOPT_HTTPHEADER => array(
                'Accept: application/xml',
                'Content-Type: application/xml',
                'Authorization: Basic ' . base64_encode($this->api_key . ':X'),
            ),
            CURLOPT_TIMEOUT => 1800,
        ]);
        return $ch;
    }

    /**
     * Get results from pre-prepared curl object
     *  - Handle errors
     *  - Parse results
g    */
    protected function runAPICurl($ch, $close=true)
    {
        // Execute
        $results = curl_exec($ch);

        // Get response code
        $response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // Make sure valid response
        if ( empty($results) ) {
            $this->error("Request Error: " . curl_error($ch));
        }

        if ( $response_code == 404 )
        {
            $this->error("Response: $response_code - check endpoint URL");
        }

        if (
            $response_code < 200
            or $response_code > 299
        ) {
            $this->error("Response: $response_code", false);
            $this->error($results);
        }

        if ($close)
        {
            curl_close($ch);
        }

        return $results;
    }

    /**
     * Set up BC API data
     * - prompt for any missing data and save to config
     */
    protected function setupAPI()
    {
        $api_url = $this->api_url;
        if (empty($api_url))
        {
            $api_url = $this->input("Enter Basecamp Classic base URL - eg. companyxyz.basecamphq.com", null, true);
        }
        $api_url = trim($api_url, " \t\n\r\0\x0B/");
        $api_url = preg_replace('~^(https?://)?([^/]+)(/.*)?$~', '$2', $api_url);
        $api_url = 'https://' . $api_url;
        $this->configure('api_url', $api_url, true);

        $api_key = $this->api_key;
        if (empty($api_key))
        {
            $api_key = $this->input("Enter Basecamp Classic API Key (from $api_url/people/me/edit)", null, true);
        }
        $api_key = trim($api_key);
        $this->configure('api_key', $api_key, true);

        $api_user_email = $this->api_user_email;
        if (empty($api_user_email))
        {
            $api_user_email = $this->input("Enter e-mail address to identify API usage", null, true);
        }
        $api_user_email = trim($api_user_email);
        $this->configure('api_user_email', $api_user_email, true);

        $this->saveConfig();
    }
}

// Check if PBCC is being used as a dependancy - do not run (child will run instead)
if (!defined('PBCC_DO_NOT_RUN') or !PBCC_DO_NOT_RUN)
{
    // Kick it all off
    Pbcc::run($argv);
}

// Note: leave this for packaging ?>
