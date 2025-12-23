# VXU (Immunization Records) transmission to CA Immunizations Registry (CAIR)

CA requires all practices to submit information about immunizations administered in their clinics. This may be done with manual data entry or as SOAP requests. 

This tool will submit VXU SOAP requests from [OpenEMR](http://open-emr.org) to [CAIR](https://cairweb.org/) and record the responses.

It is suggested to use cronjobs for automated submissions.  A typical CRON job may look like:

```
# To submit immunization records to CAIR every 4 hours
0 */4 * * * /usr/bin/php /var/www/html/mdvxu/vxu_cair.php
```

It is assumed that the CVX codes in immunization records are present in OpenEMR codes table.  Additional setup requirements are included in [setup](./setup/README.md) directory.

See also 
1. [Dennison Williams'](https://github.com/DennisonWilliams) COVID VXU utility [openemr_cair_synch](https://github.com/DennisonWilliams/openemr_cair_synch)
2. [Daniel Pflieger's](https://github.com/growlingflea) plug-ins [[1](https://github.com/growlingflea/openemr/commits/rel-501-CAIR-plug-in),[2](https://github.com/growlingflea/openemr/commits/rel-500-CAIR2-plugin)].
