<?php

##
## Program: PNP Grafana API , alternative version of https://github.com/lingej/pnp-metrics-api/raw/master/application/controller/api.php to work
## with older versions of Nagios and PNP. This file needs to be inside the folder /usr/local/nagios/share/pnp.
## To create a PNP Datasource in Grafana, check https://github.com/sni/grafana-pnp-datasource/
##
## License: GPL
## Copyright (c) 2018-2018 Dudssource (dudssource@gmail.com)
##
## This program is free software; you can redistribute it and/or
## modify it under the terms of the GNU General Public License
## as published by the Free Software Foundation; either version 2
## of the License, or (at your option) any later version.
##
## This program is distributed in the hope that it will be useful,
## but WITHOUT ANY WARRANTY; without even the implied warranty of
## MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
## GNU General Public License for more details.
##
## You should have received a copy of the GNU General Public License
## along with this program; if not, write to the Free Software
## Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
##
error_reporting(E_ALL ^E_NOTICE);
require ('include/function.inc.php');
require ('include/tpl_function.inc.php');
require ('include/debug.php');

if(getenv('PNP_CONFIG_FILE') != ""){
    $config = getenv('PNP_CONFIG_FILE');
}else{
    $config = "/usr/local/nagios/etc/pnp/config";
}

# Version info
$version=array();
if (is_readable("/usr/local/nagios/etc/pnp/pnp4nagios_release")) {
    $version_file=file("/usr/local/nagios/etc/pnp/pnp4nagios_release");
    foreach($version_file as $line) {
        $info=explode("=", $line);
        $version[$info[0]]=preg_replace("/\n|\r|\"/", "",$info[1]);
    }
}

if (is_readable($config . ".php")) {
	include ($config . ".php");
} else {
	die("<b>$config.php</b> not found");
}

if (is_readable($config . "_local.php")) {
	include ($config . "_local.php");
}

if(!isset($conf['template_dir'])){
    $conf['template_dir'] = dirname(__file__);
}

if (is_readable('./lang/lang_' . $conf['lang'] . '.php')) {
	include ('./lang/lang_' . $conf['lang'] . '.php');
} else {
	include ('./lang/lang_en.php');
}

# Debugger init
$debug = new check;

$debug->doCheck("rrdtool");
$debug->doCheck("p_open");
$debug->doCheck("fpassthru");
$debug->doCheck("xml_parser_create");
$debug->doCheck("zlib");
$debug->doCheck("gd");
$debug->doCheck("rrdbase");

// extracting the PATH
$method = explode("api.php", $_SERVER[PHP_SELF]);

// forwading the request to the right method
if(preg_match( "/\/api\/services/", $method[1] )){
    services();
} else if(preg_match( "/\/api\/hosts/", $method[1] )){
    hosts($_GET['query']);
} else if(preg_match( "/\/api\/labels/", $method[1] )){
    labels();
} else if(preg_match( "/\/api\/metrics/", $method[1] )){
    metrics();
} else {
    index();
}

/*
 * HTTP Methods
 */

/**
 * Prints the version, it's used for testing purposes.
 *
 * GET EXAMPLE: http://localhost/nagios/pnp/api.php/api/index
 */
function index() {
    global $version;
    if (!empty($version)) {
        $data['pnp_version']  = $version['PKG_VERSION'];
        $data['pnp_rel_date'] = $version['PKG_REL_DATE'];
    } else {
        $data['pnp_version']  = PNP_VERSION;
        $data['pnp_rel_date'] = PNP_REL_DATE;
    }
    $data['error'] = "";
    return_json($data, 200);
}

/*
 * Returns the list of hosts.
 *
 * GET EXAMPLE: http://localhost/nagios/pnp/api.php/api/hosts?query=/KAFKA01/
 */
function hosts($query = false) {
    $data  = array();
    $hosts = _getHosts($query);
    foreach ( $hosts as $host ){
      $data['hosts'][] = array(
        'name' => $host
      );
    }
    return_json($data, 200);
  }

/*
 * Returns the list of services per host.
 *
 * POST EXAMPLE: http://localhost/nagios/pnp/api.php/api/services/
 *
 * {
 *   "host" : "GVTSVPX-KAFKA01"
 * }
 */
function services() {
    $data  = array();
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
      // Only Post Reuests
      $data['error'] = "Only POST Requests allowed";
      return_json($data, 901);
      return;
    }
    $pdata = json_decode(file_get_contents('php://input'), TRUE);

    $host = arr_get($pdata, "host");
    if ( $host === false ){
      $data['error'] = "No hostname specified";
      return_json($data, 901);
      return;
    }
    $services   = array();
    $hosts      = _getHosts($host);
    $services   = _getServices($hosts);
    $duplicates = array();
    
    foreach($services as $service){
      // skip duplicates
      if(isset($duplicates[$service])) {
        continue;
      }
      $duplicates[$service] = true;
      $data['services'][] = array(
        'name'        => $service,
        'servicedesc' => $service,
        'hostname'    => $host
      );
    }
    return_json($data, 200);
  }

/*
 * Returns the RRD labels related to the service.
 *
 * POST EXAMPLE: http://localhost/nagios/pnp/api.php/api/labels/
 *
 * {
 *   "host" : "NAGIOSXI",
 *   "service" : "AMQ_Principal_-_mdb.central_-_entregues"
 * } 
 */
function labels() {
    $data = array();
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
      // Only Post Reuests
      $data['error'] = "Only POST Requests allowed";
      return_json($data, 901);
      return;
    }
    $pdata    = json_decode(file_get_contents('php://input'), TRUE);
    $host     = arr_get($pdata, "host");
    $service  = arr_get($pdata, "service");

    if ( $host === false ){
      $data['error'] = "No hostname specified";
      return_json($data, 901);
      return;
    }
    if ( $service === false ){
      $data['error'] = "No service specified";
      return_json($data, 901);
      return;
    }

    $hosts      = _getHosts($host);
    $services   = _getServices($hosts, $service);

    foreach($services as $service){
      try {
        // read XML file
        $xml=parse_xml($host, $service == "pnp-internal" ? ".pnp-internal" : $service, $service);
      } catch (Exception $e) {
        $data['error'] = "$e";
        return_json($data, 901);
        return;
      }


      foreach( $xml['PNP']['DS'] as $KEY) {
        $data['labels'][] = array(
          'name'     =>  "ds" . $KEY,
          'label'    =>  $service . ":" . $xml['PNP']['NAME'][$KEY],
          'service'  => $service,
          'hostname' => $host
        );
      }
    }
    return_json($data, 200);
}

/*
 * Returns the list of metrics related to the host/service during the specified timerange.
 *
 * POST EXAMPLE: http://localhost/nagios/pnp/api.php/api/metrics/
 *
 * {
 * "targets": [
 *   {
 *     "host": "GVTSVPX-KAFKA01",
 *     "service": "NONCORE_-_Kafka_Lag_Topicos",
 *     "perflabel": "NONCORE_-_Kafka_Lag_Topicos:topicSummaryDeviceGroupPipeline",
 *     "alias": "topicSummaryDeviceGroupPipeline",
 *     "type": "AVERAGE",
 *     "fill": "fill",
 *     "factor": "",
 *     "refId": "A"
 *   },
 *   {
 *     "host": "GVTSVPX-KAFKA01",
 *     "service": "NONCORE_-_Kafka_Lag_Topicos",
 *     "perflabel": "NONCORE_-_Kafka_Lag_Topicos:motor_regras",
 *     "type": "AVERAGE",
 *     "fill": "fill",
 *     "factor": "",
 *     "refId": "B"
 *   }
 * ],
 * "start": "1531623600",
 * "end": "1531788056"
 * } 
 *
 */
function metrics(){
    global $timerange;
    global $conf;
    // extract metrics for a given datasource
    // TODO Multiple sources via regex
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
      // Only Post Reuests
      $data['error'] = "Only POST Requests allowed";
      return_json($data, 901);
      return;
    }
    $hosts    = array(); // List of all Hosts
    $services = array(); // List of services for a given host
    $pdata    = json_decode(file_get_contents('php://input'), TRUE);
    $data     = array();

    if ( !isset($pdata['targets']) ){
      $data['error'] = "No targets specified";
      return_json($data, 901);
      return;
    }

    $start = arr_get($pdata,  'start');
    $end   = arr_get($pdata,  'end');
    $timerange = getTimeRange($start,$end,$view);
    $interval = arr_get($pdata,  'intervalMs') / 1000;

    foreach( $pdata['targets'] as $key => $target){
      
      $host        = arr_get($target, 'host');
      $service     = arr_get($target, 'service');
      $perflabel   = arr_get($target, 'perflabel');
      $type        = arr_get($target, 'type');
      $refid       = arr_get($target, 'refid');
      $fill        = arr_get($target, 'fill');

      if ( $host === false ){
        $data['error'] = "No hostname specified";
        return_json($data, 901);
        return;
      }
      if ( $service === false ){
        $data['error'] = "No service specified";
        return_json($data, 901);
        return;
      }
      if ( $perflabel === false ){
        $data['error'] = "No perfdata label specified";
        return_json($data, 901);
        return;
      }
      if ( $type === false ){
        $data['error'] = "No perfdata type specified";
        return_json($data, 901);
        return;
      }

      $hosts    = _getHosts($host);
      $services = _getServices($hosts, $service);

      $hk = 0; // Host Key
      foreach ( $services as $service) {
        $host    = $host;
        $service = $service;
        try {
          // read XML file
          $xml=parse_xml($host, $service == "pnp-internal" ? ".pnp-internal" : $service, $service);
        } catch (Kohana_Exception $e) {
          $data['error'] = "$e";
          return_json($data, 901);
          return;
        }
        
        // create a Perflabel List
        $perflabels = array();
        foreach( $xml['PNP']['DS'] as $value){
          $label = $service . ":" . $xml['PNP']['NAME'][$value];
          if (isRegex($perflabel)) {
              if(!preg_match( $perflabel, $label ) ){
                continue;
              }
          } elseif ( $perflabel != $label ) {
            continue;
          }
          $perflabels[] = array(
                            "label" => $label,
                            "name" => "ds" . $value, 
                            "warn"  => $xml['PNP']['WARN'][$value],
                            "crit"  => $xml['PNP']['CRIT'][$value]
          );
        }

        foreach ( $perflabels as $tmp_perflabel){
          try {
            $rrdfile = $conf['rrdbase'] . "$host/$service.rrd";
            $rrddef = $conf['rrdbase'] . $host . "/" . $service . ".xml";
            $xml = "<pnp>" . _rrdtool_fetch($rrdfile, $type, $interval) . "</pnp>";
          } catch (Kohana_Exception $e) {
            $data['error'] = "$e";
            return_json($data, 901);
            return;
          }

          $xpd   = simplexml_load_string($xml);
          $i = 0;
          $index = -1;

          $step                   = (string) 60;
          $data['targets'][$key][$hk]['start']       = $start * 1000;
          $data['targets'][$key][$hk]['end']         = $end * 1000;
          $data['targets'][$key][$hk]['host']        = $host;
          $data['targets'][$key][$hk]['service']     = $service;
          $data['targets'][$key][$hk]['perflabel']   = $tmp_perflabel['label'];
          $data['targets'][$key][$hk]['type']        = $type;
          $data['targets'][$key][$hk]['fill']        = $fill;

          $i  = 0;
            foreach ( $xpd->row as $row){
              $datetime = DateTime::createFromFormat("d.m.y H:i", $row->time, new DateTimeZone( "America/Sao_Paulo" ));
              $timestamp = $datetime->getTimestamp() * 1000;
              $d = (string) $row->$tmp_perflabel['name'];
              if ($d == "NaN"){
                $d = null;
              }else{
                $d = floatval($d);
              }
              $data['targets'][$key][$hk]['datapoints'][] = array( $d, $timestamp );
              $i++;
            }

          $hk++;

        }
      }
    }

    return_json($data, 200);
}


/*
 * Internal functions.
 */
function _rrdtool_fetch($rrdfile, $type, $interval) {
    global $debug;
    global $conf;
    global $timerange;
    $descriptorspec = array (
        0 => array ("pipe","r"), // stdin is a pipe that the child will read from
        1 => array ("pipe","w") // stdout is a pipe that the child will write to
    );

    $process = proc_open($conf['rrdtool']." - ", $descriptorspec, $pipes);
    if (is_resource($process)) {
        $command = " fetch ".$rrdfile." ".$type." -r ".$interval." -s ".$timerange['start']." -e ".$timerange['end'];
        fwrite($pipes[0], $command);
        fclose($pipes[0]);

        $buffer = fgets($pipes[1]);
        if (preg_match('/^ERROR/', $buffer)) {
            $deb['data'] = $buffer;
            $deb['command'] = format_rrd_debug($command);
            $deb['opt'] = $opt;
            $debug->doCheck("rrdgraph",$deb);
        }
        ob_start();
        $buffer = "";
        while (!feof($pipes[1])) {
            $array = split(" ", (fgets($pipes[1], 4096)));
            $temp_buffer = "";
            $count = 0;
            if(preg_match("/^\d+:/",$array[0])){
            foreach($array as $key){
                if($count == 0){
                    $temp_buffer .= "<row><time>".date($conf['date_fmt'],$key)."</time>";
                }else{
                    $temp_buffer .= "<ds$count>".floatval($key)."</ds$count>";
                }
                $count++;
            }
            $buffer .= $temp_buffer."</row>\n";
        }
    }
    ob_end_clean();
    fclose($pipes[1]);
    proc_close($process);
    }
    return $buffer;
}


/*
* return array key
*/
function arr_get($array, $key=false, $default=false){
  if ( isset($array) && $key == false ){
    return $array;
  }
  $keys = explode(".", $key);
  foreach ($keys as $key_part) {
    if ( isset($array[$key_part] ) === false ) {
      if (! is_array($array) or ! array_key_exists($key_part, $array)) {
        return $default;
      }
    }
    $array = $array[$key_part];
  }
  return $array;
}

/*
 * Converts the content to the JSON format and prints the result in the response.
 */
function return_json( $data, $status=200 ){
  $json = json_encode($data);
  header('Status: '.$status);
  header('Content-type: application/json');
  print $json;
}

/*
 * Checks if the string looks like a regex.
 */
function isRegex($string){
  // if string looks like an regex /regex/
  if ( substr($string,0,1) == "/" && substr($string,-1,1) == "/" && strlen($string) >= 2 ){
    return true;
  }else{
    return false;
  }
}

/*
 * Wrapper to the native api to extract the hosts and format the result.
 */
function _getHosts($query) {
  global $conf;
  $result  = array();
  $hosts   = getHosts();
  $isRegex = false;
  if ($query !== NULL && isRegex($query) ) {
    $isRegex = true;
  }
  foreach ( $hosts as $host ){
  list($name,$state) = explode(";",$host);
    if ( $state != 0 ){
      continue;
    }
    if($isRegex) {
      if(preg_match("$query", $name) ) {
        $result[] = $name;
      }
    }
    elseif ($query !== NULL) {
      if("$query" == $name) {
        $result[] = $name;
      }
    } else {
      $result[] = $name;
    }
  }
  if ($query !== NULL && $query == ".pnp-internal") {
    $result[] = ".pnp-internal";
  }
  return($result);
}

/*
 * Wrapper to the native api, returns list of service hashes
 */
function _getServices($hosts, $query) {
  global $conf;
  $result = array();
  $isRegex = false;
  if ($query !== NULL && isRegex($query) ) {
    $isRegex = true;
  }
  foreach ( $hosts as $host){
    $services = getServices($conf["rrdbase"], $host);
    foreach ($services as $value) {
      $value = explode(";", $value);
      $servicename=$value[0];
      $servicedown=$value[1];
      // if the service RRD file wasn't modified in the last conf[max_age] seconds, we ignore the service
      if ($servicedown == 1) {
        continue;
      }
      if ($isRegex) {
        if ( preg_match("$query", $servicename)) {
          $result[] = $servicename;
        }
      }
      elseif ($query !== NULL) {
        if("$query" == $servicename || "$query" == $servicename) {
          $result[] = $servicename;
        }
      } else {
        $result[] = $servicename;
      }
    }
  }
  return($result);
}

?>
