# ODR Environment Readme

The read-only file `odr_env.py` in this directory contains a number of helper functions so you don't need to write your own OAuth (https://en.wikipedia.org/wiki/OAuth) client to interface with ODR's API routines.

In order to use the file's helper functions in a Jupyter Notebook, you'll need to import it like any other python module.
```
import odr_env
```
After that, you can use any of the helper functions defined in `odr_env.py` inside the Notebook.

---
* `odr_env.getDatatypeList()`

   This function returns a dict containing basic data on all top-level datatypes you're allowed to view.  This function is useful to identify which datatype you're interested in, since you'll need its `datatype_id` for the next function in this list.

---
* `odr_env.getDatarecordList(datatype_id)`

   This function returns a dict containing basic data on all the top-level datarecords belonging to `datatype_id` that you're allowed to view.  This function can only return useful identifying information if the datatype's designer has properly set the "External ID Datafield" and/or the "Name Datafield" for the datatype in question.

---
* `odr_env.getDatarecordData(datarecord_id)`

   This function returns a dict containing all the datafields, child datarecords, and linked datarecords that you're allowed to view on a given top-level `datarecord_id`.

---
* `odr_env.getFileList(datarecord_id)`

   This utility function parses the dict returned by `odr_env.getDatarecordData(datarecord_id)`, and returns a new dict with basic identifying data on all the files contained within.

---
* `odr_env.downloadFile(file_id, filename)`

   This function attempts to download a file from ODR by `file_id`, and writes the contents of that file into `filename` inside your Jupyter Notebook directory if successful.  Currently, only files smaller than 5Mb can be downloaded with this function.
