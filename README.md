# capture-lookups
A Symfony Console command. Searches for configuration file that lists URLs of Google Sheets, grabs the Sheets and stores their data locally as CSV files.

Designed be used in the context of the Symfony Console application at https://github.com/forikal-uk/xml-authoring-tools which, in turn, is used in the context of a known directory structure which is based on [xml-authoring-project](https://github.com/forikal-uk/xml-authoring-project).


# Usage instructions

## Specifying the Lookup tables to collect

We assume this command is run in the context of an [xml-authoring-project](https://github.com/forikal-uk/xml-authoring-project). ie. the key aspects of the structure of the directory is known.

Use the `mapping.yaml` configuration file which defines the locations of the Google Sheets we must collect.

### Example mapping.yaml

```yaml
LookupTableA:
  # (string) Specifies the URL of the sheet to look into
  url: "https://docs.google.com/spreadsheets/d/1jOfsClbTj15YUqE-X2Ai9cvyhP-GLvP8CGZPgD1TysI/edit#gid=0"
  # (int) Sets at what row number we'll start reading data - use if you want to skip the beginning of the sheet, for example a header
  startingFromRow: 2
  
  # (bool) Enable or disable fetching data in a batch. Doing so is faster, but may fail if there is a lot of data to be fetched
  batchGet: true
  
LookupTableB:
  url: "https://docs.google.com/spreadsheets/d/1jOfsClbTj15YUqE-X2Ai9cvyhP-GLvP8CGZPgD1TysI/edit#gid=0"
  startingFromRow: 2
  batchGet: false
```  

## Using the command

1. Checkout the repository
1. Install dependencies with `composer install`
1. Put a `credentials.json` file in the Symfony project root or anywhere in any of the parent directories accessible to PHP
1. Issue `bin/console forikal:capture-lookups` to see all available mappings
1. Issue `bin/console forikal:capture-lookups --sheet=LookupTableA` to run the command interactively
1. Issue `bin/console forikal:capture-lookups --sheet=LookupTableA --no-interaction` to run the command without any prompts, skipping risky file names or existing files
1. Issue `bin/console forikal:capture-lookups --sheet=LookupTableA --no-interaction --force` to run the command without any prompts, **overwriting existing files** and **using sanitised file names**
 

## Skipped Tabs - Naming convention

By _Google Sheet tab_ I mean one of the sheets _within_ a workbook. 

Any Google Sheet tab which has a trailing underscore will be considered to be skipped. 

* `foo_` *is* skipped.
* `foo` is not skipped.
* `_foo` is *not* skipped either. 

## Connecting to GSuite

The file that Google Api uses to authenticate access to GSuite should be in the root of the [xml-authoring-project](https://github.com/forikal-uk/xml-authoring-project).

The [ping-drive project explains how to get set up to connect to GSuite](https://github.com/forikal-uk/ping-drive#usage).


## Run the command

When the command is run, it will:

* Search for the scapesettings.yaml in the current working directory, if not found it will look in the parent recursively until a file named scapesettings.yaml is found.
* Determine the `DestinationDirectory` to write-to:
  * If `DestinationDirectory` option is passed to command, use that.
  * If no `DestinationDirectory` option is passed to command, set it to the default `DestinationDirectory` (see below). 
    * The default `DestinationDirectory` is the working directory in which the command was invoked. 
* For each Lookup table specified in the configuration file:
  * Go to the Google Sheet on GSuite
  * Determine and note the name of the Google Sheet
  * For each tab in that sheet:
    * If the tab's name indicates it should be ignored (has a trailing underscore), ignore that tab, skip and move on to the next tab.
    * Else, note the tab name
    * Combine the Google Sheet name with the tab name to set the resulting CSV file's name: `<GoogleSheetName>-<TabName>.csv`. 
    * Check the name to ensure it is made of only alphanumeric characters, dot, hyphen or underscore. (i.e the name is less likely to cause issues if used as a filename on Windows or MacOS)  
    * If the name contains invalid characters, write a meaningful error message to STD_OUT and STD_ERR and exit with an error code.  
    * Check to see if a CSV file matching that name is already stored in the destination directory
    * If it is already present and the `-f` (--force) flag  is NOT set, ask user "Permission to overwrite the file y/n?". With the suggested default prompt being no, `[n]`.
    * If it is already present and the -f (--force) flag  is set, overwrite the existing file without prompting the user.
    * Else, create a CSV file with the chosen name. 
    * Write the contents of the Google Sheet Tab as a CSV file. (comma delimeter, double quotes used to encapsulate strings)  



