# gooddata-writer-v3
[![Build Status](https://travis-ci.org/keboola/gooddata-writer-v3.svg?branch=master)](https://travis-ci.org/keboola/gooddata-writer-v3)
[![Code Climate](https://codeclimate.com/github/keboola/gooddata-writer-v3/badges/gpa.svg)](https://codeclimate.com/github/keboola/gooddata-writer-v3)

GoodData Writer v3 for Keboola Docker Runner

The Writer by default alters GoodData project model according to the configuration and then loads data. 

Tables can be loaded sequentially or at once (see https://help.gooddata.com/display/developer/Multiload+of+CSV+Data).

For data load of selected tables use flag `loadOnly` to prevent model update (which would delete all non-uploaded tables from project).

## Configuration

```json
{
  "parameters": {
    "user": {
      "login": "",
      "#password": ""
    },
    "project": {
      "pid": ""
    },
    "dimensions": {
      "date_dimension": {
        "title": "Date Dimension",
        "identifier": "",
        "includeTime": true,
        "template": "keboola"
      }
    },
    "tables": {
      "out.c-main.products": {
        "title": "Products",
        "incrementalLoad": 0,
        "grain": null,
        "columns": {
          "id": {
              "type": "CONNECTION_POINT",
              "title": "Id"
          },
          "name": {
              "type": "ATTRIBUTE",
              "title": "Name"
          },
          "created": {
              "type": "DATE",
              "format": "yyyy-MM-dd HH:mm:ss",
              "dateDimension": "created_date"
          },
          "category": {
              "type": "REFERENCE",
              "schemaReference": "out.c-main.categories"
          },
          "price": {
              "type": "FACT",
              "title": "Price",
              "dataType": "DECIMAL",
              "dataTypeSize": "8,2"
          }
        }
      }
    },
    "multiLoad": false
  }
}
```

- **user** contains GoodData credentials
    - **login**
    - **#password**
- **project** contains pid of GoodData project
    - **pid**
    - **backendUrl** (optional) - custom base url to a white-labelled GoodData
- **multiLoad** - flag if the tables should be integrated one by one or all at once (may be good for some situations, see https://help.gooddata.com/display/developer/Multiload+of+CSV+Data)
- **loadOnly** - skip model update, just load data 
- **dimensions** contains list of configured date dimensions
    - dimension's name is in object's key
    - **title** - pretty name of the dimension in GoodData
    - **idenitifer** (optional) - custom identifier of the dimension (default: `[date_dimension].dataset.dt`)
    - **includeTime** - flag if the date contains also the time dimension (default: `false`)
    - **template** - name of date dimension template (default: `gooddata`)
- **tables** contains list of configured tables to load
    - table's Storage API identifier is in object's key
    - **identifier** (optional) - custom identifier of the dataset in GoodData
    - **title** - pretty name of the dataset in GoodData
    - **incrementalLoad** - should be empty or `0` for full load or contain number of days which are used for export of table from Storage
    - **grain** (optional) - array of columns used as a fact grain (see https://help.gooddata.com/display/developer/Set+the+Grain+of+a+Fact+Table+to+Avoid+Duplicate+Records)
    - **anchorIdentifier** (optional) - custom GoodData identifier of an implicit connection point
    - **columns** - definition of columns
        - name of the column from data table is in key of the object
        - **type** - column type, one of: `CONNECTION_POINT`, `ATTRIBUTE`, `LABEL`, `HYPERLINK`, `FACT`, `DATE`, `REFERENCE`, `IGNORE`
        - **title** - pretty name of the column in GoodData (makes sense only for types `CONNECTION_POINT`, `ATTRIBUTE` and `FACT`)
        - **dataType** - data type, one of: `BIGINT`, `DATE`, `DECIMAL`, `INT`, `VARCHAR` (makes sense only for types `CONNECTION_POINT`, `ATTRIBUTE` and `FACT`)
        - **dataTypeSize** - number of characters if `dataType` is `VARCHAR` (e.g. 255) or range if `dataType` is `DECIMAL` (e.g. 15,2)
        - **reference** - name of referenced column if `type` is `LABEL` or `HYPERLINK`
        - **schemaReference** - Storage API id of referenced table if `type` is `REFERENCE`
        - **sortLabel** - name of label column used for sorting if `type` is `ATTRIBUTE`
        - **sortOrder** - order of sorting if `sortLabel` is used, one of: `ASC`, `DESC` (default: `ASC`)
        - **format** - format of the data in date dimension column if `type` is `DATE` (e.g. `yyyy-MM-dd`), supported formats are:
            - `yyyy` – year (e.g. 2010)
            - `MM` – month (01 - 12)
            - `dd` – day (01 - 31)
            - `hh` – hour (01 - 12)
            - `HH` – hour 24 format (00 - 23)
            - `mm` – minutes (00 - 59)
            - `ss` – seconds (00 - 59)
            - `kk/kkkk` – microseconds or fractions of seconds (00-99, 000-999, 0000-9999)
            - `GOODDATA` - number of days since 1900-01-01
        - **dateDimension** - name of referenced date dimension if `type` is `DATE`
        - **identifier** - custom GoodData identifier of the column  (makes sense only for types `CONNECTION_POINT`, `ATTRIBUTE` and `FACT`)
        - **identifierLabel** - custom GoodData identifier of attribute's default label (makes sense only for type `ATTRIBUTE`)
        - **identifierSortLabel** - custom GoodData identifier of attribute's sort label (makes sense only for type `ATTRIBUTE`)
        

## Read model

Configuration can be read from project model using `read-model` sync action with configuration parameters:

- **user** contains GoodData credentials
    - **login**
    - **#password**
- **project** contains pid of GoodData project
    - **pid**
    - **backendUrl** (optional) - custom base url to a white-labelled GoodData
- **bucket** name of bucket where data tables will be created
        
## Tests

Tests require setup of some env variables. Integration test connects to a GoodData Provisioning instance. You need to provide its url (`PROVISIONING_URL`) and token of a KBC project which will be used for provisioning (`KBC_TOKEN`). Also, you need to provision a GoodData user (`GD_USERNAME` and `GD_PASSWORD`) and two projects (`GD_PID` and `GD_PID_2`).
