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

`--start-at="PATTERN"`

&nbsp;&nbsp;&nbsp;&nbsp;Start the processing of the JSON file at PATTERN (useful if execution of the file failed).

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

## batch-create.php

Run from the `create/` directory: `php batch-create.php --username=U --password=P [--dry-run] [--no-confirm] [--create-summary="..."] [--start-at=N] [--first-task-id=N] jobs/FILE.json`

### Automatic task ids

Task pages can be named with placeholders instead of hard-coded ids:

```
"Gestion:Rédaction/Tâches/__task_id_1__": { "create": "{{Tâche de rédaction|title=__title__|...}}" },
"Gestion:Rédaction/Tâches/__task_id_2__": { "create": "..." }
```

- On the first allocation, the script scans `Gestion:Rédaction/Tâches/` and starts at the highest existing id + 1 (or at `--first-task-id=N`).
- Each candidate id is checked for existence before use, so pages created by someone else during the run are skipped.
- A placeholder keeps its id for the whole item: `__task_id_1__` can also be used in page content.
- The first page of an item is treated as the article: if it already exists, or is not created (canceled, error), the rest of the item (tasks) is skipped.
- Existing task pages with hard-coded ids trigger a warning.

`scripts/convert-task-ids.py FILE...` rewrites hard-coded ids in a job file into `__task_id_N__` placeholders (counter restarts at each `"vars"` block; works on draft files that are not valid JSON yet).

### Rerunning a job

If the article (first page of an item) already exists, the whole item is skipped, including its tasks. A job can therefore be rerun after a failure without duplicating anything. Tasks for articles that already exist must be created by hand.
