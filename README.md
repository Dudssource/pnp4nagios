# pnp4nagios
 Nagios PNP Grafana API , an alternative version of https://github.com/lingej/pnp-metrics-api/raw/master/application/controller/api.php that works with older versions of Nagios core (4.0.8 - 4.2.4) , PNP (0.4.14) and rrdtool (1.3.8).
 This api was created to be used by the [sni-pnp-datasource](https://grafana.com/plugins/sni-pnp-datasource), which is a Grafana backend datasource using PNP4Nagios/PNP NPCD to access RRD files.
 This api is also intended to be compatible with the Nagios CentOS VM.
 Internally this api doensn´t work like the lingej version because of the version of the rrdtool used (the latest version of the Nagios CentOS VM comes with rrdtool 1.3.8 installed), but it works very well :smile:


## Installation

 To install it, just get the latest version from the respository and save it inside the `/usr/local/nagios/share/pnp/` folder. No extra configuration is required.

`wget "https://github.com/Dudssource/pnp4nagios/raw/master/api.php" -O /usr/local/nagios/share/pnp/api.php`

To install and configure the Grafana datasource, check the [official](https://grafana.com/plugins/sni-pnp-datasource/installation) tutorial.

## Tips
- The Grafana datasource should be configured with Basic Authentication
- If you can´t find a service listed in the Services combo, it´s probably because or the service doesn´t have generated performance data or it´ because the RRD file related to the service wasn't modified in the last 6 hours (it probably means that the service is inactive). This time can be changed in the file `/usr/local/nagios/etc/pnp/config.php`, configuration `$conf['max_age']`
