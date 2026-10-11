<?php

// Load MediaWiki API library
require_once '../vendor/autoload.php';

$dryRun = false; // Do the parsing and the page retrieval but do not save.
$noConfirm = false; // Do not ask for confirmation when saving a change.
$username = false;
$password = false;
$apiUrl = "https://wikimedi.ca/api.php";
//$apiUrl = "http://localhost/projects/Wikimedica/api.php";
$createFile = false;
$createSummary = '';
$startAt = null;
$changeTags = ['batch-page-create-script'];
$taskPrefix = 'Gestion:Rédaction/Tâches/'; // Pages named with __task_id__ placeholders get the next free id under this prefix.
$nextTaskId = null; // Next task id to try, set by --first-task-id or found on first allocation.

array_shift($argv); // First argument is the script name.

// Iterate and normalize all arguments.
foreach($argv as $a){
    
    $separator = strpos($a, '=');
    $arg = $separator == false ? $a: substr($a, 0, $separator); // Allow arguments without values (like --dry-run).
    $value = substr($a, $separator + 1);
    
    switch($arg) {
        case '--username':
            $username = $value;
            break;
        case '--password':
            $password = $value;
            break;
        case '--dry-run':
            $dryRun = true;
            break;
        case '--no-confirm':
            $noConfirm = true;
            break;
        case '--create-summary':
            $createSummary = $value;
            if($createSummary[0] == '"') { $createSummary = substr($createSummary, 1, strlen($createSummary) - 1);  } // Remove quotes.
            break;
        case '--first-task-id':
            $nextTaskId = (int)$value;
            break;
        case '--start-at':
            $startAt = $value;
            break;
        default:
            // This should be the file name.
            $createFile = $arg;
        break;
    }
}

if(!$username) { die("Fatal: username is missing\n"); } 
if(!$password) { die("Fatal: password is missing\n"); }
if(!$createFile) { die("Fatal: create file path missing\n"); }

// Load and parse the JSON file.
$jsonData = file_get_contents($createFile);

if(!$jsonData) { die("Fatal: JSON file does not exist or could not be read\n"); }

$json = json_decode($jsonData, true);

if(!$json) { die("Fatal: JSON parsing failed\n"); }

// Create an authenticated API and services
$auth = new \Addwiki\Mediawiki\Api\Client\Auth\UserAndPassword( $username, $password );
$api = new \Addwiki\Mediawiki\Api\Client\Action\ActionApi( $apiUrl, $auth );
$services = new \Addwiki\Mediawiki\Api\MediawikiFactory( $api );
$getter = $services->newPageGetter();

if($startAt && !isset($json[$startAt])) { die("--start-at pattern not found"); }

$count = 0; // A count for the number of jobs the user is at.
$total = count($json);

// If processing the JSON file should start at a specific pattern.
if($startAt) {
    foreach($json as $k => $v) { // Crude but works.
        if($k == $startAt) { break; }
        unset($json[$k]);
        $count++;
    }
}

// Start processing batch create json.
foreach($json as $index => $item) {
    $count++;
    
    if(!is_array($item)) { die("Fatal: item $index json invalid\n"); }
    
    // Extract variables for template substitution
    $vars = isset($item['vars']) ? $item['vars'] : [];
    
    // Add current date to vars
    $vars['date'] = date('Y-m-d');
    
    echo "$count / $total : Processing item ".($index +1 )." ... \n";
    
    $isFirstPage = true; // The first page of an item is the article; the others (tasks) depend on it.
    
    // Process each page to create
    foreach($item as $pageTitle => $pageConfig) {
        if($pageTitle === 'vars') continue; // Skip the vars section
        
        if(!is_array($pageConfig)) { die("Fatal: $pageTitle config json invalid\n"); }
        
        // Allocate task ids for the __task_id__ / __task_id_X__ placeholders found in the title.
        // A placeholder keeps the same id for the whole item, so it can be reused in content.
        if(preg_match_all('/__(task_id(?:_\w+?)?)__/', $pageTitle, $m)) {
            foreach($m[1] as $placeholder) {
                if(!isset($vars[$placeholder])) {
                    $vars[$placeholder] = allocateTaskId();
                    echo " ... Allocated task id {$vars[$placeholder]} to __{$placeholder}__\n";
                }
            }
        }
        
        // Substitute variables in the page title
        $pageTitle = substituteVars($pageTitle, $vars);
        
        // Check if page already exists
        if($getter->getFromTitle($pageTitle)->getId() !== 0) {
            if($isFirstPage) { // Tasks use auto ids: running them again would duplicate them, so the item is skipped (the script can be rerun safely).
                echo " ... [Page $pageTitle already exists, skipping the whole item]\n";
                break;
            }
            
            if(strpos($pageTitle, $taskPrefix) === 0) {
                echo " ... [WARNING: task page $pageTitle already exists (hard-coded id collision?), skipping. Use __task_id_X__ placeholders instead]\n";
            } else {
                echo " ... [Page $pageTitle already exists, skipping]\n";
            }
            continue;
        }
        
        // Process create action
        if(isset($pageConfig['create'])) {
            echo " ... Creating page $pageTitle ... \n";
            
            // Create the page
            $created = createPage($pageTitle, substituteVars($pageConfig['create'], $vars), $createSummary);
            
            if(!$created && $isFirstPage) {
                echo " ... [Article not created, skipping the rest of the item]\n";
                break;
            }
        }
        
        // Process redirects if any
        createRedirects($pageTitle, $pageConfig, $vars);
        
        $isFirstPage = false;
    }
    
    echo "\n";
}

/**
 * Creates the redirects listed in a page config, skipping those that already exist.
 * @param string $pageTitle the redirect target
 * @param array $pageConfig
 * @param array $vars
 */
function createRedirects($pageTitle, $pageConfig, $vars) {
    global $getter, $createSummary;
    
    if(!isset($pageConfig['redirects']) || !is_array($pageConfig['redirects'])) { return; }
    
    foreach($pageConfig['redirects'] as $redirectTitle) {
        // Substitute variables in redirect titles too
        $redirectTitle = substituteVars($redirectTitle, $vars);
        
        if($getter->getFromTitle($redirectTitle)->getId() !== 0) {
            echo " ... [Redirect $redirectTitle already exists, skipping]\n";
            continue;
        }
        
        echo " ... Creating redirect $redirectTitle ... \n";
        createPage($redirectTitle, "#REDIRECTION [[$pageTitle]]", $createSummary);
    }
}

/**
 * Replaces __var__ placeholders with their values.
 * @param string $text
 * @param array $vars
 * @return string
 */
function substituteVars($text, $vars) {
    foreach($vars as $varName => $varValue) {
        $text = str_replace("__{$varName}__", $varValue, $text);
    }
    
    return $text;
}

/**
 * Returns the next free task id (Gestion:Rédaction/Tâches/N).
 * On first call, the starting point is --first-task-id or the highest existing id + 1.
 * Every candidate is checked for existence before being returned, so ids created
 * by someone else while the script runs are skipped.
 * @return int
 */
function allocateTaskId() {
    global $api, $getter, $nextTaskId, $taskPrefix;
    
    if($nextTaskId === null) {
        $nextTaskId = findHighestTaskId() + 1;
        echo " ... Highest existing task id is ".($nextTaskId - 1)."\n";
    }
    
    while($getter->getFromTitle($taskPrefix.$nextTaskId)->getId() !== 0) {
        echo " ... [Task id $nextTaskId already taken, trying next]\n";
        $nextTaskId++;
    }
    
    return $nextTaskId++;
}

/**
 * Scans all pages under Gestion:Rédaction/Tâches/ and returns the highest numeric id.
 * @return int
 */
function findHighestTaskId() {
    global $api, $taskPrefix;
    
    list($nsName, $prefix) = explode(':', $taskPrefix, 2);
    
    // Resolve the namespace id.
    $result = $api->request(\Addwiki\Mediawiki\Api\Client\Action\Request\ActionRequest::simpleGet('query', [
        'meta' => 'siteinfo', 'siprop' => 'namespaces', 'formatversion' => 2
    ]));
    
    $nsId = null;
    foreach($result['query']['namespaces'] as $ns) {
        if($ns['name'] === $nsName || (isset($ns['canonical']) && $ns['canonical'] === $nsName)) { $nsId = $ns['id']; break; }
    }
    
    if($nsId === null) { die("Fatal: namespace $nsName not found\n"); }
    
    $max = 0;
    $params = ['list' => 'allpages', 'apnamespace' => $nsId, 'apprefix' => $prefix, 'aplimit' => 'max', 'formatversion' => 2];
    
    do {
        $result = $api->request(\Addwiki\Mediawiki\Api\Client\Action\Request\ActionRequest::simpleGet('query', $params));
        
        foreach($result['query']['allpages'] as $page) {
            $id = substr($page['title'], strlen($taskPrefix));
            if(ctype_digit($id)) { $max = max($max, (int)$id); }
        }
        
        if(isset($result['continue']['apcontinue'])) { $params['apcontinue'] = $result['continue']['apcontinue']; }
        else { break; }
    } while(true);
    
    return $max;
}

/**
 * Creates a new page with the given content.
 * @param string $pageTitle
 * @param string $content
 * @param string $summary
 * @return bool true if the page was created (or would have been, in dry run).
 */
function createPage($pageTitle, $content, $summary) {
    global $noConfirm, $dryRun, $services, $changeTags;
    
    
    if(!$noConfirm) {
        echo " ... ... Confirm creation of page '$pageTitle'? [y/n] ";
        
        if (!in_array(trim(fgets(STDIN)), array('y', 'Y'))) {
            echo " ... [Canceled]\n";
            return false;
        }
    }
    
    if($dryRun) {
        echo "... [Nothing saved (dry run)]\n";
        return true;
    }
    
    try {
        // Create the page content
        $pageContent = new \Addwiki\Mediawiki\DataModel\Content($content);
        
        // Create the page identifier
        $pageIdentifier = new \Addwiki\Mediawiki\DataModel\PageIdentifier(
            new \Addwiki\Mediawiki\DataModel\Title($pageTitle)
        );
        
        // Create the revision with change tags
        $revision = new \Addwiki\Mediawiki\DataModel\Revision(
            $pageContent,
            $pageIdentifier,
            null,
            new \Addwiki\Mediawiki\DataModel\EditInfo($summary, false, false, null, $changeTags)
        );
        
        // Save the page
        $result = $services->newRevisionSaver()->save($revision);
        
        if($result) {
            echo " ... [Created successfully]\n";
            
            // Sleep 1 second between API calls when noConfirm is true (rate limiting)
            if($noConfirm) {
                sleep(1);
            }
            
            return true;
        }
        
        echo " ... [Failed to create]\n";
    } catch (Exception $e) {
        echo " ... [Error: " . $e->getMessage() . "]\n";
    }
    
    return false;
}

echo "Batch creation completed.\n";
?>
