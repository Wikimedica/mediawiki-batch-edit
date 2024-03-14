# mediawiki-batch-edit
A command-line interface to make back edit to a MediaWiki installation.

## Command line arguments

Run the command from the root directory of you MediaWiki instalation.

`--user=USERNAME`
  The username of the user that will be doing the edits.
`--commit-message="MESSAGE"`
  The commit message of the edits.
`--edit-list=PATH`
  A path to the file that contains the edits that need to be done.

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

### Edit pages matching a pattern
