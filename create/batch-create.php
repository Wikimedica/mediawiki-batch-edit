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
        case '--commit-message':
            $commitMessage = $value;
            if($commitMessage[0] == '"') { $commitMessage = substr($commitMessage, 1, strlen($commitMessage) - 1);  } // Remove quotes.
            break;
        case '--dry-run':
            $dryRun = true;
            break;
        case '--no-confirm':
            $noConfirm = true;
            break;
        case '--create-summary':
            $createSummary = $value;
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
    
    // Process each page to create
    foreach($item as $pageTitle => $pageConfig) {
        if($pageTitle === 'vars') continue; // Skip the vars section
        
        if(!is_array($pageConfig)) { die("Fatal: $pageTitle config json invalid\n"); }
        
        // Substitute variables in the page title
        foreach($vars as $varName => $varValue) {
            $pageTitle = str_replace("__{$varName}__", $varValue, $pageTitle);
        }
        
        // Check if page already exists
        if($getter->getFromTitle($pageTitle)->getId() !== 0) {
            echo " ... [Page $pageTitle already exists, skipping]\n";
            continue;
        }
        
        // Process create action
        if(isset($pageConfig['create'])) {
            $content = $pageConfig['create'];
            
            // Replace variables in the content
            foreach($vars as $varName => $varValue) {
                $content = str_replace("__{$varName}__", $varValue, $content);
            }
            
            echo " ... Creating page $pageTitle ... \n";
            
            // Create the page
            createPage($pageTitle, $content, $createSummary);
        }
        
        // Process redirects if any
        if(isset($pageConfig['redirects']) && is_array($pageConfig['redirects'])) {
            foreach($pageConfig['redirects'] as $redirectTitle) {
                // Substitute variables in redirect titles too
                
                foreach($vars as $varName => $varValue) {
                    $redirectTitle = str_replace("__{$varName}__", $varValue, $redirectTitle);
                }
                
                if($getter->getFromTitle($redirectTitle)->getId() !== 0) {
                    echo " ... [Redirect redirectTitle already exists, skipping]\n";
                    continue;
                }
                
                $redirectContent = "#REDIRECTION [[$pageTitle]]";
                echo " ... Creating redirect $redirectTitle ... \n";
                
                createPage($redirectTitle, $redirectContent, $createSummary);
            }
        }
    }
    
    echo "\n";
}

/**
 * Creates a new page with the given content.
 * @param string $pageTitle
 * @param string $content
 * @param string $summary
 */
function createPage($pageTitle, $content, $summary) {
    global $noConfirm, $dryRun, $services, $changeTags;
    
    
    if(!$noConfirm) {
        echo " ... ... Confirm creation of page '$pageTitle'? [y/n] ";
        
        if (!in_array(trim(fgets(STDIN)), array('y', 'Y'))) {
            echo " ... [Canceled]\n";
            return;
        }
    }
    
    if($dryRun) {
        echo "... [Nothing saved (dry run)]\n";
        return;
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
        } else {
            echo " ... [Failed to create]\n";
        }
    } catch (Exception $e) {
        echo " ... [Error: " . $e->getMessage() . "]\n";
    }
}

echo "Batch creation completed.\n";
?>
