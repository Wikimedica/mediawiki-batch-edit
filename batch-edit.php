<?php

// Load MediaWiki API library
require_once 'vendor/autoload.php';

$dryRun = false; // Do the parsing and the page retrieval but do not save.
$noConfirm = false; // Do not ask for confirmation when saving a change.
$username = false;
$password = false;
$apiUrl = "https://wikimedi.ca/api.php";
//$apiUrl = "http://localhost/projects/Wikimedica/api.php";
$editFile = false;
$includeRedirects = false;
$editSummary = '';
$startAt = null;

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
            $commmitMessage = $value;
            if($commitMessage[0] = '"') { $commitMessage = substr($commitMessage, 1, strlen($commitMessage) - 1);  } // Remove quotes.
            break;
        case '--dry-run':
            $dryRun = true;
            break;
        case '--no-confirm':
            $noConfirm = true;
            break;
        case '--include-redirects':
            $includeRedirects = true;
            break;
        case '--edit-summary':
            $editSummary = $value;
            break;
        case '--start-at':
            $startAt = $value;
            break;
        default:
            // This should be the file name.
            $editFile = $arg;
        break;
    }
}

if(!$username) { die("Fatal: username is missing\n"); } 
if(!$password) { die("Fatal: password is missing\n"); }
if(!$editFile) { die("Fatal: edit file path missing\n"); }

// Load and parse the JSON file.
$jsonData = file_get_contents($editFile);

if(!$jsonData) { die("Fatal: JSON file does not exist or could not be read\n"); }

$json = json_decode($jsonData, true);

if(!$json) { die("Fatal: JSON parsing failed\n"); }

// Create an authenticated API and services
$auth = new \Addwiki\Mediawiki\Api\Client\Auth\UserAndPassword( $username, $password );
$api = new \Addwiki\Mediawiki\Api\Client\Action\ActionApi( $apiUrl, $auth );
$services = new \Addwiki\Mediawiki\Api\MediawikiFactory( $api );
$getter = $services->newPageGetter();

if($startAt && !isset($json[$startAt])) { die("--start-at pattern not found"); }

// If processing the JSON file should start at a specific pattern.
if($startAt) {
    foreach($json as $k => $v) { // Crude but works.
        if($k == $startAt) { break; }
        unset($json[$k]);
    }
}

// Start processing batch edit json.
foreach($json as $pattern => $edit) {

    if(!is_array($edit)) { die("Fatal: $pattern edit json invalid\n"); }
    
    $pages = []; // The pages that have been matched.

    switch(isset($edit['match']) ? $edit['match']: 'exact') { // Match exact patterns by default.
        case 'exact':

            echo "Searching for pattern $pattern ... ";
            if(!$page = $getter->getFromTitle( $pattern )) {
                echo "[Not found]\n";
                continue 2;
            }
            echo "[Found 1 page]\n";
            $pages[] = $page;

        break;
        default:
            die("Fatal: match type not supported for $pattern\n");
    }

    unset($edit['match']);

    $revision = $page->getRevisions()->getLatest();
    if(!$revision) {
         echo " ... No revisions found, page may have been deleted\n";
        continue;
    }
    
    $content = $revision->getContent()->getData();

    if(!$includeRedirects && strpos($content, '#REDIRECTION') !== false) {
        echo "... [Page is a redirect, skipping]\n";
        continue;
    }

    foreach($edit as $workType => $configs) {
        if(!is_array($configs)) { die("Fatal: $pattern job configs json invalid\n"); }
        switch($workType) {
            case 'replace':
                foreach($configs as $job) {
                    if(!is_array($job)) { die("Fatal: $pattern job config json invalid\n"); }
                    if(!isset($job['type'])) { die("Fatal: $pattern job type not provided\n"); }

                    switch($job['type']) {
                        case 'template': templateJob($page, $content, $job); break;
                        default: die("Fatal: $pattern job type not supported\n");
                    }
                }

                break;
            default: 
                die("Fatal: $pattern work type not supported\n");
        }
    }
}

/**
 * Does a job on a template.
 * @param \AddWiki\DataModel\Page $page
 * @param string $content
 * @param array $config the job's config
 */
function templateJob($page, $content, $config){
    if(!isset($config['template-name'])) { die("Fatal: missing template-name\n"); }

    $templateNames = [];

    echo " ... Replacing for template ".$config['template-name']." ... ";

    if($config['template-name'][0] == '/') { // This is a regex.

        preg_match_all('/\{\{'.substr($config['template-name'], 1), $content, $matches);

        // Find all templates that match the pattern.
        foreach($matches[0] as $match) {
            // Extract just the template names.
            if(strpos($match, '|')) { $match = substr($match, 0, strpos($match, '|')); } // Template name ends at |.
            else if (strpos($match, '}}')) { $match = substr($match, 0, strpos($match, '}}')); } // Or it ends at }}.

            $templateNames[] = str_replace('{{', '', $match); 
        }
        $templateNames = array_unique($templateNames);
        
    } else { $templateNames[] = $config['template-name']; }

    // Do some validation and some normalization.
    if(!isset($config['arguments']) && !isset($config['values'])) { die("Fatal: missing job config\n"); }
    if(isset($config['arguments']) && !is_array($config['arguments'])) {  die("Fatal: arguments should be an array\n"); } 
    else if(!isset($config['arguments'])) { $config['arguments'] = []; }
    if(isset($config['values']) && !is_array($config['values'])) {  die("Fatal: values should be an array\n"); } 
    elseif(!isset($config['values'])) { $config['values'] = []; }

    $somethingDone = false;

    foreach($templateNames as $template) {
        $data = parseForTemplateCall($content, $template);
        
        if(!$data) { continue; }

        foreach($config['values'] as $k => $v) {
            if(isset($data[0][$k]) ) { // If the parameter is already defined.
                if($data[0][$k] == strval($v)) { continue; } // The value has not changed.
                echo "\n ... ... Parameter $k value ".$data[0][$k]." to be replaced with $v in $template\n";
            } else { echo "\n ... ... Parameter $k with value $v to be added in $template\n"; }
            
            $somethingDone = true;
            $data[0][$k] = $v;
        }

        foreach($config['arguments'] as $k => $v) {

            if(!$data[0][$k]) { continue; } // That argument is not in the template.
            if(isset($data[0][$v])) { continue; } // The parameter already exists.

            $val = $data[0][$k];
            unset($data[0][$k]);
            $data[0][$v] = $val;

            $somethingDone = true;
            echo " ... argument $k to be replaced with $v in $template\n";
        }

        // Build the new template.
        $newTemplate = '{{';
        $lineEnd = strpos(trim($data[1], "\n"), "\n") ? "\n": ''; // If this was a multiline template call, the new template should reflect that.
        $newTemplate .= $template.$lineEnd;
        foreach($data[0] as $k => $v) { $newTemplate .= "| $k = $v".$lineEnd; }
        $newTemplate .= '}}';
        
        $content = str_replace($data[1], $newTemplate, $content);
    }

    if(!$somethingDone) {
        echo "[Nothing done]\n";
        return;
    }

    global $noConfirm;
    global $dryRun;
    global $editSummary;
    global $services;

    // Edit the page with the new template.
    $content = new \Addwiki\Mediawiki\DataModel\Content( $content );
    $revision = new \Addwiki\Mediawiki\DataModel\Revision( 
        $content, 
        $page->getPageIdentifier(),
        null,
        new \Addwiki\Mediawiki\DataModel\EditInfo($editSummary)
    );

    if(!$noConfirm) {
        echo " ... ... Confirm change? [y/n] ";

        if (!in_array(trim(fgets(STDIN)), array('y', 'Y'))) {
            echo " ... [Canceled]\n";
            return;
        }
    }

    if($dryRun) {
        echo "... [Nothing saved (dry run)]\n";
    }
    else { echo $services->newRevisionSaver()->save( $revision ) ? " ... [Done]\n" : " ... [Failed]\n";  }
}

/**
 * Parses a string for a template call.
 * @param string $content
 * @param string $name the name of the template structure to retrieve.
 * @return array() the structure of the template call and the raw template text.
 * */
function parseForTemplateCall($content, $name)
{
    $matches;
    
    $delimiter_wrap  = '~';
    $delimiter_left  = '{{';/* put YOUR left delimiter here.  */
    $delimiter_right = '}}';/* put YOUR right delimiter here. */
    
    $delimiter_left  = preg_quote( $delimiter_left,  $delimiter_wrap );
    $delimiter_right = preg_quote( $delimiter_right, $delimiter_wrap );
    $pattern = $delimiter_wrap . $delimiter_left
    . '((?:[^' . $delimiter_left . $delimiter_right . ']++|(?R))*)'
            . $delimiter_right . $delimiter_wrap;
            
    //preg_match_all('/{{(.*?)}}/', $content, $matches);
    preg_match_all($pattern, $content, $matches);
    
    if(empty($matches[1])) // If no template calls were found.
    {
        return [false, false];
    }
    
    $matches = $matches[1];
    $raw;
    $template;
    
    foreach($matches as $match)
    {
        $raw = '{{'.$match.'}}';
        $match = trim($match);
        if(strpos($match, $name) === 0) // Find the wanted template
        {
            $template = $match;
            break;
        }
    }
    
    if(!$template)
    {
        return [false, $raw];
    }
    
    $args; // Extract the parameters.
    preg_match_all('/\|((.|\n)*?)\=/', $template, $args);
    $vals; // Extract the values.
    preg_match_all('/\=((.|\n)*?)(\||\z)/', $template, $vals);
    
    if(empty($args[1])) // If no parameters were passed to the template.
    {
        return [[], $raw];
    }
    
    $data = [];
    
    foreach($args[1] as $i => $arg)
    {
        $data[trim($arg)] = trim($vals[1][$i], " \n");
    }
    
    return [$data, $raw];
}
?>