# Setup Instructions before VXU submissions

1. Complete the CAIR registration steps and obtain valid Organization Code and Password to be included in each VXU subumission.

2. Create hl7log table in your OpenEMR database to record submissions and responses. Review and run [2.hl7log.sql](./2.hl7log.sql).

3. Copy and update [3.mdvxu.env.sample](./3.mdvxu.env.sample) to the dotenv subdirectory of *home* of the user that will run these background jobs (example *//home/emrsys/dotenv/mdvxu.env*).<br>This utility does not use any of OpenEMR standard sql.inc or sqlconf.php files to get database login information.

4. CAIR requires valid manufacturer names as specified in [HL7 Table 0227 - Manufacturers of Vaccines (MVX)](https://www2a.cdc.gov/vaccines/iis/iisstandards/vaccines.asp?rpt=mvx). This utility expects MVX entries are maintained in codes table. Add a new code type MVX simillar to CVX. Included [4.load.codes.MVX.csv](./4.load.codes.MVX.csv) file may be used to upload MVX list in the codes table. *This file assumes the MVX code_type is 998.*

5. CAIR also validates CVX-MVX pairs as specified in [HL7 Standard Code Set Mapping product names to CVX and MVX](https://www2.cdc.gov/vaccines/iis/iisstandards/vaccines.asp?rpt=tradename#prod). This utility expects these pair entries are maintained in list_options table. Review and run [5.load.list_options.cvx-mvx.csv](./5.load.list_options.cvx-mvx.csv) to create/update CVX-MVX list.

