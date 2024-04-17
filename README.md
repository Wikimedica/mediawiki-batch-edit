# mediawiki-batch-edit
A command-line interface to make back edit to a MediaWiki installation.

## Command line arguments

Run the command from the root directory of you MediaWiki instalation.
The last argument is the path to the JSON file.

`--username=USERNAME`

&nbsp;&nbsp;&nbsp;&nbsp;The username of the user that will be doing the edits.

`--password=PASSWORD`

&nbsp;&nbsp;&nbsp;&nbsp;The password of the user that will be doing the edits.
  
`--commit-message="MESSAGE"`

&nbsp;&nbsp;&nbsp;&nbsp;The commit message of the edits.

`--dry-run`

&nbsp;&nbsp;&nbsp;&nbsp;Run the script but do not make any edits.

`--include-redirects`

&nbsp;&nbsp;&nbsp;&nbsp;Page redirections are skipped by default, include them in the matches.

`--edit-summary="SUMMARY"`

&nbsp;&nbsp;&nbsp;&nbsp;The edit summary.

`--no-confirm`

&nbsp;&nbsp;&nbsp;&nbsp;Do not ask for a confirmation before saving a page.

## JSON file structure

### Edit single pages

```
{
  "Exact_page_name": {
    "match": "exact",
    "replace": [
        {
          "type": "template",
          "template-name": "Template",
          "template-name": "/Template regex/",
          "values": {
            "arg1": null
            "arg2": "new value"
          },
          "arguments": {
            "old_argument_name": "new_argument_name"
          }
        },
        {
          "type": "text",
          "match": "text to match",
          "replacement": "replacement text"
        },  
    ]
  },
  ...
}
```
