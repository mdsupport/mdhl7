### HL7 based OpenEMR transmissions


This package currently supports following HL7 interfaces to OpenEMR

- VXU for CA Immunizations Registry (CAIR)
- Generic Lab Order results

#### Common Setup
1. Create hl7log table in your OpenEMR database to record submissions and responses. Review and run [2.hl7log.sql](./2.hl7log.sql).

2. Copy and update [3.mdhl7.env.sample](./3.mdhl7.env.sample) to the dotenv subdirectory of *home* of the user that will run these background jobs (example - //home/**emrsys**/dotenv/mdhl7.env).<br> *Utilities in this package do not use any of OpenEMR standard files. So database login information contained in sqlconf.php files should be specified in mdhl7.env file.*