<?php

/*
 * TileServer.php project
 * ======================
 * https://github.com/klokantech/tileserver-php/
 * Copyright (C) 2014 - Klokan Technologies GmbH
 */

global $config;
$config['serverTitle'] = 'TileServer-php v1';
//$config['baseUrls'] = array('t0.server.com', 't1.server.com');

Router::serve(array(
    '/' => 'Server:getHtml',
    '/test' => 'Server:getInfo',
    '/html' => 'Server:getHtml',
    '/:alpha/:number/:number/:number.grid.json' => 'Json:getUTFGrid',
    '/:alpha.json' => 'Json:getJson',
    '/:alpha.jsonp' => 'Json:getJsonp',
    '/wmts' => 'Wmts:get',
    '/wmts/1.0.0/WMTSCapabilities.xml' => 'Wmts:get',
    '/wmts/:alpha/:number/:number/:alpha' => 'Wmts:getTile',
    '/wmts/:alpha/:alpha/:number/:number/:alpha' => 'Wmts:getTile',
    '/wmts/:alpha/:alpha/:alpha/:number/:number/:alpha' => 'Wmts:getTile',
    '/:alpha/:number/:number/:alpha' => 'Wmts:getTile',
    '/tms' => 'Tms:getCapabilities',
    '/tms/:alpha' => 'Tms:getLayerCapabilities',
));

/**
 * Server base
 */
class Server {

  /**
   * Configuration of TileServer [baseUrls, serverTitle]
   * @var array
   */
  public $config;

  /**
   * Datasets stored in file structure
   * @var array
   */
  public $fileLayer = array();

  /**
   * Datasets stored in database
   * @var array
   */
  public $dbLayer = array();

  /**
   * PDO database connection
   * @var object
   */
  public $db;

  /**
   * Set config
   */
  public function __construct() {
    $this->config = $GLOBALS['config'];
  }

  /**
   * Looks for datasets
   */
  public function setDatasets() {
    $mjs = glob('*/metadata.json');
    $mbts = glob('*.mbtiles');
    if ($mjs) {
      foreach (array_filter($mjs, 'is_readable') as $mj) {
        $layer = $this->metadataFromMetadataJson($mj);
        array_push($this->fileLayer, $layer);
      }
    }
    if ($mbts) {
      foreach (array_filter($mbts, 'is_readable') as $mbt) {
        $this->dbLayer[] = $this->metadataFromMbtiles($mbt);
      }
    }
  }

  /**
   * Processing params from router <server>/<layer>/<z>/<x>/<y>.ext
   * @param array $params
   */
  public function setParams($params) {
    if (isset($params[1])) {
      $this->layer = $params[1];
    }
    $params = array_reverse($params);
    if (isset($params[2])) {
      $this->z = $params[2];
      $this->x = $params[1];
      $file = explode('.', $params[0]);
      $this->y = $file[0];
      $this->ext = isset($file[1]) ? $file[1] : NULL;
    }
  }

  /**
   * Get variable don't independent on sensitivity
   * @param string $key
   * @return boolean
   */
  public function getGlobal($isKey) {
    $get = $_GET;
    foreach ($get as $key => $value) {
      if (strtolower($isKey) == strtolower($key)) {
        return $value;
      }
    }
    return FALSE;
  }

  /**
   * Testing if is a database layer
   * @param string $layer
   * @return boolean
   */
  public function isDBLayer($layer) {
    if (is_file($layer . '.mbtiles')) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Testing if is a file layer
   * @param string $layer
   * @return boolean
   */
  public function isFileLayer($layer) {
    if (is_dir($layer)) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   *
   * @param string $jsonFileName
   * @return array
   */
  public function metadataFromMetadataJson($jsonFileName) {
    $metadata = json_decode(file_get_contents($jsonFileName), true);
    $metadata = $this->metadataValidation($metadata);
    $metadata['basename'] = str_replace('/metadata.json', '', $jsonFileName);
    return $metadata;
  }

  /**
   * Loads metadata from MBtiles
   * @param string $mbt
   * @return object
   */
  public function metadataFromMbtiles($mbt) {
    $metadata = array();
    $this->DBconnect($mbt);
    $result = $this->db->query('select * from metadata');

    $resultdata = $result->fetchAll();
    foreach ($resultdata as $r) {
      $value = preg_replace('/(\\n)+/','',$r['value']);
      $metadata[$r['name']] = addslashes($value);
    }
    if (!array_key_exists('minzoom', $metadata)
    || !array_key_exists('maxzoom', $metadata)
    ) {
      // autodetect minzoom and maxzoom
      $result = $this->db->query('select min(zoom_level) as min, max(zoom_level) as max from tiles');
      $resultdata = $result->fetchAll();
      if (!array_key_exists('minzoom', $metadata))
        $metadata['minzoom'] = $resultdata[0]['min'];
      if (!array_key_exists('maxzoom', $metadata))
        $metadata['maxzoom'] = $resultdata[0]['max'];
    }
    // autodetect format using JPEG magic number FFD8
    if (!array_key_exists('format', $metadata)) {
      $result = $this->db->query('select hex(substr(tile_data,1,2)) as magic from tiles limit 1');
      $resultdata = $result->fetchAll();
      $metadata['format'] = ($resultdata[0]['magic'] == 'FFD8')
        ? 'jpg'
        : 'png';
    }
    // autodetect bounds
    if (!array_key_exists('bounds', $metadata)) {
      $result = $this->db->query('select min(tile_column) as w, max(tile_column) as e, min(tile_row) as s, max(tile_row) as n from tiles where zoom_level='.$metadata['maxzoom']);
      $resultdata = $result->fetchAll();
      $w = -180 + 360 * ($resultdata[0]['w'] / pow(2,$metadata['maxzoom']));
      $e = -180 + 360 * ((1+$resultdata[0]['e']) / pow(2,$metadata['maxzoom']));
      $n = $this->row2lat($resultdata[0]['n'], $metadata['maxzoom']);
      $s = $this->row2lat($resultdata[0]['s']-1, $metadata['maxzoom']);
      $metadata['bounds'] = implode(',', array($w, $s, $e, $n));
    }
    $metadata = $this->metadataValidation($metadata);
    $mbt = explode('.', $mbt);
    $metadata['basename'] = $mbt[0];
    return $metadata;
  }

  /**
   * Convert row number to latitude of the top of the row
   * @param integer $r
   * @param integer $zoom
   * @return integer
   */
   public function row2lat($r, $zoom) {
     $y = $r / pow(2,$zoom-1) - 1;
     return rad2deg(2.0 * atan(exp(3.191459196*$y)) - 1.57079632679489661922);
   }

  /**
   * Valids metaJSON
   * @param object $metadata
   * @return object
   */
  public function metadataValidation($metadata) {
    if (array_key_exists('bounds', $metadata)) {
      $metadata['bounds'] = array_map('floatval', explode(',', $metadata['bounds']));
    } else {
      $metadata['bounds'] = array(-180, -85.051128779807, 180, 85.051128779807);
    }
    if (!array_key_exists('profile', $metadata)) {
      $metadata['profile'] = 'mercator';
    }
// TODO: detect thumb / SQL for mbtiles
    if (array_key_exists('minzoom', $metadata))
      $metadata['minzoom'] = intval($metadata['minzoom']);
    else
      $metadata['minzoom'] = 0;
    if (array_key_exists('maxzoom', $metadata))
      $metadata['maxzoom'] = intval($metadata['maxzoom']);
    else
      $metadata['maxzoom'] = 18;
    if (!array_key_exists('format', $metadata)) {
      $metadata['format'] = 'png';
    }
    return $metadata;
  }

  /**
   * SQLite connection
   * @param string $tileset
   */
  public function DBconnect($tileset) {
    try {
      $this->db = new PDO('sqlite:' . $tileset, '', '', array(PDO::ATTR_PERSISTENT => true));
    } catch (Exception $exc) {
      echo $exc->getTraceAsString();
      die;
    }

    if (!isset($this->db)) {
      header('Content-type: text/plain');
      echo 'Incorrect tileset name: ' . $tileset;
      die;
    }
  }

  /**
   * Check if file is modified and set Etag headers
   * @param string $filename
   * @return boolean
   */
  public function isModified($filename) {
    $filename = $filename . '.mbtiles';
    $lastModifiedTime = filemtime($filename);
    $eTag = md5($lastModifiedTime);
    header("Last-Modified: " . gmdate("D, d M Y H:i:s", $lastModifiedTime) . " GMT");
    header("Etag:" . $eTag);
    if (@strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $lastModifiedTime ||
            @trim($_SERVER['HTTP_IF_NONE_MATCH']) == $eTag) {
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * Returns tile of dataset
   * @param string $tileset
   * @param integer $z
   * @param integer $y
   * @param integer $x
   * @param string $ext
   */
  public function renderTile($tileset, $z, $y, $x, $ext) {
    if ($this->isDBLayer($tileset)) {
      if ($this->isModified($tileset) == TRUE) {
        header('Access-Control-Allow-Origin: *');
        header('HTTP/1.1 304 Not Modified');
        die;
      }
      $this->DBconnect($tileset . '.mbtiles');
      $z = floatval($z);
      $y = floatval($y);
      $x = floatval($x);
      $flip = true;
      if ($flip) {
        $y = pow(2, $z) - 1 - $y;
      }
      $result = $this->db->query('select tile_data as t from tiles where zoom_level=' . $z . ' and tile_column=' . $x . ' and tile_row=' . $y);
      $data = $result->fetchColumn();
      if (!isset($data) || $data === FALSE) {
        //if tile doesn't exist
        //select scale of tile (for retina tiles)
        $result = $this->db->query('select value from metadata where name="scale"');
        $resultdata = $result->fetchColumn();
        $scale = isset($resultdata) && $resultdata !== FALSE ? $resultdata : 1;
        $this->getCleanTile($scale, $ext);
      } else {
        $result = $this->db->query('select value from metadata where name="format"');
        $resultdata = $result->fetchColumn();
        $format = isset($resultdata) && $resultdata !== FALSE ? $resultdata : 'png';
        if ($format == 'jpg') {
          $format = 'jpeg';
        }
        if ($format == 'pbf') {
          header('Content-type: application/x-protobuf');
          header('Content-Encoding:gzip');
        } else {
          header('Content-type: image/' . $format);
        }
        header('Access-Control-Allow-Origin: *');
        echo $data;
      }
    } elseif ($this->isFileLayer($tileset)) {
      $name = './' . $tileset . '/' . $z . '/' . $x . '/' . $y;
      $mime = 'image/';
      if($ext != NULL){
        $name .= '.' . $ext;
      }
      if ($fp = @fopen($name, 'rb')) {
        if($ext != NULL){
          $mime .= $ext;
        }else{
          //detect image type from file
          $mimetypes = array('gif', 'jpeg', 'png');
          $mime .= $mimetypes[exif_imagetype($name) - 1];
        }
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($name));
        fpassthru($fp);
        die;
      } else {
        //scale of tile (for retina tiles)
        $meta = json_decode(file_get_contents($tileset.'/metadata.json'));
        if(!isset($meta->scale)){
          $meta->scale = 1;
        }
      }
      $this->getCleanTile($meta->scale, $ext);
    } else {
      header('HTTP/1.1 404 Not Found');
      echo 'Server: Unknown or not specified dataset "'.$tileset.'"';
      die;
    }
  }

  /**
   * Returns clean tile
   * @param integer $scale Default 1
   */
  public function getCleanTile($scale = 1, $format = 'png') {
    switch ($format) {
      case 'pbf':
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: application/json; charset=utf-8');
        echo '{"message":"Tile does not exist"}';
        die;
      case 'png':
      default:
        $tileSize = 256 * $scale;
        $png = imagecreatetruecolor($tileSize, $tileSize);
        imagesavealpha($png, true);
        $trans_colour = imagecolorallocatealpha($png, 0, 0, 0, 127);
        imagefill($png, 0, 0, $trans_colour);
        header('Access-Control-Allow-Origin: *');
        header('Content-type: image/png');
        imagepng($png);
        die;
    }
  }

  /**
   * Returns tile's UTFGrid
   * @param string $tileset
   * @param integer $z
   * @param integer $y
   * @param integer $x
   */
  public function renderUTFGrid($tileset, $z, $y, $x, $flip = TRUE) {
    if ($this->isDBLayer($tileset)) {
      if ($this->isModified($tileset) == TRUE) {
        header('HTTP/1.1 304 Not Modified');
      }
      if ($flip) {
        $y = pow(2, $z) - 1 - $y;
      }
      try {
        $this->DBconnect($tileset . '.mbtiles');

        $query = 'SELECT grid FROM grids WHERE tile_column = ' . $x . ' AND '
                . 'tile_row = ' . $y . ' AND zoom_level = ' . $z;
        $result = $this->db->query($query);
        $data = $result->fetch(PDO::FETCH_ASSOC);

        if ($data !== FALSE) {
          $grid = gzuncompress($data['grid']);
          $grid = substr(trim($grid), 0, -1);

          //adds legend (data) to output
          $grid .= ',"data":{';
          $kquery = 'SELECT key_name as key, key_json as json FROM grid_data '
                  . 'WHERE zoom_level=' . $z . ' and '
                  . 'tile_column=' . $x . ' and tile_row=' . $y;
          $result = $this->db->query($kquery);
          while ($r = $result->fetch(PDO::FETCH_ASSOC)) {
            $grid .= '"' . $r['key'] . '":' . $r['json'] . ',';
          }
          $grid = rtrim($grid, ',') . '}}';
          header('Access-Control-Allow-Origin: *');

          if (isset($_GET['callback']) && !empty($_GET['callback'])) {
            header('Content-Type:text/javascript charset=utf-8');
            echo $_GET['callback'] . '(' . $grid . ');';
          } else {
            header('Content-Type:text/javascript; charset=utf-8');
            echo $grid;
          }
        } else {
          header('Access-Control-Allow-Origin: *');
          echo '{}';
          die;
        }
      } catch (Exception $e) {
        header('Content-type: text/plain');
        print 'Error querying the database: ' . $e->getMessage();
      }
    } else {
      echo 'Server: no MBTiles tileset';
      die;
    }
  }

  /**
   * Returns server info
   */
  public function getInfo() {
//    echo $this->config['baseUrls'][0];die;
    $this->setDatasets();
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    header('Content-Type: text/html;charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $this->config['serverTitle'] . '</title></head><body>';
    echo '<h1>' . $this->config['serverTitle'] . '</h1>';
    echo 'TileJSON service: <a href="//' . $this->config['baseUrls'][0] . '/index.json">' . $this->config['baseUrls'][0] . '/index.json</a><br>';
    echo 'WMTS service: <a href="//' . $this->config['baseUrls'][0] . '/wmts">' . $this->config['baseUrls'][0] . '/wmts</a><br>';
    echo 'TMS service: <a href="//' . $this->config['baseUrls'][0] . '/tms">' . $this->config['baseUrls'][0] . '/tms</a>';
    foreach ($maps as $map) {
      $extend = '[';
      foreach ($map['bounds'] as $ext) {
        $extend = $extend . ' ' . $ext;
      }
      $extend = $extend . ' ]';
      if (strpos($map['basename'], 'mbtiles') !== false) {
        echo '<p>Available MBtiles tileset: ' . $map['basename'] . '<br>';
      } else {
        echo '<p>Available file tileset: ' . $map['basename'] . '<br>';
      }
      echo 'Metadata: <a href="//' . $this->config['baseUrls'][0] . '/' . $map['basename'] . '.json">'
      . $this->config['baseUrls'][0] . '/' . $map['basename'] . '.json</a><br>';
      echo 'Bounds: ' . $extend . '</p>';
    }
    echo '<p>Copyright (C) 2014 - Klokan Technologies GmbH</p>';
    echo '</body></html>';
  }

  /**
   * Returns html viewer
   */
  public function getHtml() {
    $this->setDatasets();
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    header('Content-Type: text/html;charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $this->config['serverTitle'] . '</title>';
    echo '<link rel="stylesheet" type="text/css" href="//cdn.klokantech.com/tileviewer/v1/index.css" />
          <script src="//cdn.klokantech.com/tileviewer/v1/index.js"></script><body>
          <script>tileserver({index:"' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/index.json", tilejson:"' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/%n.json", tms:"' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/tms", wmts:"' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/wmts"});</script>
          <h1>Welcome to ' . $this->config['serverTitle'] . '</h1>
          <p>This server distributes maps to desktop, web, and mobile applications.</p>
          <p>The mapping data are available as OpenGIS Web Map Tiling Service (OGC WMTS), OSGEO Tile Map Service (TMS), and popular XYZ urls described with TileJSON metadata.</p>';
    if (!isset($maps)) {
      echo '<h3 style="color:darkred;">No maps available yet</h3>
            <p style="color:darkred; font-style: italic;">
            Ready to go - just upload some maps into directory:' . getcwd() . '/ on this server.</p>
            <p>Note: The maps can be a directory with tiles in XYZ format with metadata.json file.<br/>
            You can easily convert existing geodata (GeoTIFF, ECW, MrSID, etc) to this tile structure with <a href="http://www.maptiler.com">MapTiler Cluster</a> or open-source projects such as <a href="http://www.klokan.cz/projects/gdal2tiles/">GDAL2Tiles</a> or <a href="http://www.maptiler.org/">MapTiler</a> or simply upload any maps in MBTiles format made by <a href="http://www.tilemill.com/">TileMill</a>. Helpful is also the <a href="https://github.com/mapbox/mbutil">mbutil</a> tool. Serving directly from .mbtiles files is supported, but with decreased performance.</p>';
    } else {
      echo '<ul>';
      foreach ($maps as $map) {
        echo "<li>" . $map['name'] . '</li>';
      }
      echo '</ul>';
    }
    echo '</body></html>';
  }

}

/**
 * JSON service
 */
class Json extends Server {

  /**
   * Callback for JSONP default grid
   * @var string
   */
  private $callback = 'grid';

  /**
   * @param array $params
   */
  public $layer = 'index';

  /**
   * @var integer
   */
  public $z;

  /**
   * @var integer
   */
  public $y;

  /**
   * @var integer
   */
  public $x;

  /**
   * @var string
   */
  public $ext;

  /**
   *
   * @param array $params
   */
  public function __construct($params) {
    parent::__construct();
    parent::setParams($params);
    if (isset($_GET['callback']) && !empty($_GET['callback'])) {
      $this->callback = $_GET['callback'];
    }
  }

  /**
   * Adds metadata about layer
   * @param array $metadata
   * @return array
   */
  public function metadataTileJson($metadata) {
    $metadata['tilejson'] = '2.0.0';
    $metadata['scheme'] = 'xyz';
    $tiles = array();
    foreach ($this->config['baseUrls'] as $url) {
      $url = '' . $this->config['protocol'] . '://' . $url . '/' .
              $metadata['basename'] . '/{z}/{x}/{y}';
      if(strlen($metadata['format']) <= 4){
        $url .= '.' . $metadata['format'];
      }
      $tiles[] = $url;
    }
    $metadata['tiles'] = $tiles;
    if ($this->isDBLayer($metadata['basename'])) {
      $this->DBconnect($metadata['basename'] . '.mbtiles');
      $res = $this->db->query('SELECT * FROM grids LIMIT 1;');
      if ($res) {
        foreach ($this->config['baseUrls'] as $url) {
          $grids[] = '' . $this->config['protocol'] . '://' . $url . '/' . $metadata['basename'] . '/{z}/{x}/{y}.grid.json';
        }
        $metadata['grids'] = $grids;
      }
    }
    if (array_key_exists('json', $metadata)) {
      $mjson = json_decode(stripslashes($metadata['json']));
      foreach ($mjson as $key => $value) {
        $metadata[$key] = $value;
      }
      unset($metadata['json']);
    }
    return $metadata;
  }

  /**
   * Creates JSON from array
   * @param string $basename
   * @return string
   */
  private function createJson($basename) {
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    if ($basename == 'index') {
      $output = '[';
      foreach ($maps as $map) {
        $output = $output . json_encode($this->metadataTileJson($map)) . ',';
      }
      if (strlen($output) > 1) {
        $output = substr_replace($output, ']', -1);
      } else {
        $output = $output . ']';
      }
    } else {
      foreach ($maps as $map) {
        if (strpos($map['basename'], $basename) !== false) {
          $output = json_encode($this->metadataTileJson($map));
          break;
        }
      }
    }
    if (!isset($output)) {
      echo 'TileServer: unknown map ' . $basename;
      die;
    }
    return stripslashes($output);
  }

  /**
   * Returns JSON with callback
   */
  public function getJson() {
    parent::setDatasets();
    header('Access-Control-Allow-Origin: *');
    header("Content-Type: application/json; charset=utf-8");
    if ($this->callback !== 'grid') {
      echo $this->callback . '(' . $this->createJson($this->layer) . ');'; die;
    } else {
      echo $this->createJson($this->layer); die;
    }
  }

  /**
   * Returns JSONP with callback
   */
  public function getJsonp() {
    parent::setDatasets();
    header('Access-Control-Allow-Origin: *');
    header("Content-Type: application/javascript; charset=utf-8");
    echo $this->callback . '(' . $this->createJson($this->layer) . ');';
  }

  /**
   * Returns UTFGrid in JSON format
   */
  public function getUTFGrid() {
    parent::renderUTFGrid($this->layer, $this->z, $this->y, $this->x);
  }

}

/**
 * Web map tile service
 */
class Wmts extends Server {

  /**
   * @param array $params
   */
  public $layer;

  /**
   * @var integer
   */
  public $z;

  /**
   * @var integer
   */
  public $y;

  /**
   * @var integer
   */
  public $x;

  /**
   * @var string
   */
  public $ext;

  /**
   *
   * @param array $params
   */
  public function __construct($params) {
    parent::__construct();
    if (isset($params)) {
      parent::setParams($params);
    }
  }

  /**
   * Tests request from url and call method
   */
  public function get() {
    $request = $this->getGlobal('Request');
    if ($request !== FALSE && $request == 'gettile') {
      $this->getTile();
    } else {
      parent::setDatasets();
      $this->getCapabilities();
    }
  }
  
  /**
   * Default TileMetrixSet for Pseudo Mercator projection 3857
   * @return string TileMatrixSet xml
   */
  public function getMercatorTileMatrixSet(){
    $name = 'GoogleMapsCompatible';
    $extent = array(-20037508.34,-20037508.34,20037508.34,20037508.34);
    $scalesBase = 559082264.0287178;
    $scales = array();

    for($i = 0; $i <= 18; $i++){
      $scales[] = $scalesBase / pow(2, $i);
    }

    return $this->getTileMatrixSet($name, $scales, $extent);
  }

  /**
   * Prints WMTS tilematrixset
   * @param string $name
   * @param array $scales Array of scales
   * @param array $extent Boundingbox of matrix
   * @param string $crs Code of crs eg: EPSG:3857
   * @param array $matrixRatio Ratio of matrix sides
   * @param array $tilesize Size of tile in pixels
   * @return string TileMatrixSet xml
   */
  public function getTileMatrixSet($name, $scales, $extent, $crs = 'EPSG:3857', $matrixRatio = array(1, 1), $tilesize = array(256, 256)){
    $srs = explode(':', $crs);
    $TileMatrixSet = '<TileMatrixSet>
      <ows:Title>' . $name . '</ows:Title>
      <ows:Abstract>' . $name . ' '. $crs .'</ows:Abstract>
      <ows:Identifier>' . $name . '</ows:Identifier>
      <ows:SupportedCRS>urn:ogc:def:crs:'.$srs[0].'::'.$srs[1].'</ows:SupportedCRS>';
   // $TileMatrixSet .= '<WellKnownScaleSet>urn:ogc:def:wkss:OGC:1.0:GoogleMapsCompatible</WellKnownScaleSet>';
    for($i = 0; $i <= sizeof($scales); $i++){
      $matrixWidth = pow(2, $i);
    $TileMatrixSet .= '
      <TileMatrix>
        <ows:Identifier>' . $i . '</ows:Identifier>
        <ScaleDenominator>' . $scales[$i] . '</ScaleDenominator>
        <TopLeftCorner>'. $extent[0] . ' ' . $extent[3] .'</TopLeftCorner>
        <TileWidth>' . $tilesize[0] . '</TileWidth>
        <TileHeight>' . $tilesize[1] . '</TileHeight>
        <MatrixWidth>' . $matrixWidth * $matrixRatio[0] . '</MatrixWidth>
        <MatrixHeight>' . $matrixWidth * $matrixRatio[1] . '</MatrixHeight>
      </TileMatrix>';
    }
    $TileMatrixSet .= '</TileMatrixSet>';

    return $TileMatrixSet;
  }

  /**
   * Returns tilesets getCapabilities
   */
  public function getCapabilities() {
    header("Content-type: application/xml");
    echo '<?xml version="1.0" encoding="UTF-8" ?>
<Capabilities xmlns="http://www.opengis.net/wmts/1.0" xmlns:ows="http://www.opengis.net/ows/1.1" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:gml="http://www.opengis.net/gml" xsi:schemaLocation="http://www.opengis.net/wmts/1.0 http://schemas.opengis.net/wmts/1.0/wmtsGetCapabilities_response.xsd" version="1.0.0">
  <!-- Service Identification -->
  <ows:ServiceIdentification>
    <ows:Title>tileserverphp</ows:Title>
    <ows:ServiceType>OGC WMTS</ows:ServiceType>
    <ows:ServiceTypeVersion>1.0.0</ows:ServiceTypeVersion>
  </ows:ServiceIdentification>
  <!-- Operations Metadata -->
  <ows:OperationsMetadata>
    <ows:Operation name="GetCapabilities">
      <ows:DCP>
        <ows:HTTP>
          <ows:Get xlink:href="' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/wmts/1.0.0/WMTSCapabilities.xml">
            <ows:Constraint name="GetEncoding">
              <ows:AllowedValues>
                <ows:Value>RESTful</ows:Value>
              </ows:AllowedValues>
            </ows:Constraint>
          </ows:Get>
          <!-- add KVP binding in 10.1 -->
          <ows:Get xlink:href="' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/wmts?">
            <ows:Constraint name="GetEncoding">
              <ows:AllowedValues>
                <ows:Value>KVP</ows:Value>
              </ows:AllowedValues>
            </ows:Constraint>
          </ows:Get>
        </ows:HTTP>
      </ows:DCP>
    </ows:Operation>
    <ows:Operation name="GetTile">
      <ows:DCP>
        <ows:HTTP>
          <ows:Get xlink:href="' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/wmts/">
            <ows:Constraint name="GetEncoding">
              <ows:AllowedValues>
                <ows:Value>RESTful</ows:Value>
              </ows:AllowedValues>
            </ows:Constraint>
          </ows:Get>
          <ows:Get xlink:href="' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/wmts?">
            <ows:Constraint name="GetEncoding">
              <ows:AllowedValues>
                <ows:Value>KVP</ows:Value>
              </ows:AllowedValues>
            </ows:Constraint>
          </ows:Get>
        </ows:HTTP>
      </ows:DCP>
    </ows:Operation>
  </ows:OperationsMetadata>
  <Contents>';
    
    $customtileMatrixSets = '';
    
    //layers
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    $mercator = new GlobalMercator();
    foreach ($maps as $m) {
      if (strpos($m['basename'], '.') !== false) {
        $basename = explode('.', $m['basename']);
      } else {
        $basename = $m['basename'];
      }
      $title = (array_key_exists('name', $m)) ? $m['name'] : $basename;
      $profile = $m['profile'];
      $bounds = $m['bounds'];
      $format = $m['format'] == 'hybrid' ? 'jpgpng' : $m['format'];
      $mime = ($format == 'jpg') ? 'image/jpeg' : 'image/' . $format;
      
      if ($profile == 'geodetic') {
        $tileMatrixSet = "WGS84";
      }elseif ($m['profile'] == 'custom') {
        //TODO: Each custom neads each tileset BUG!!
        $crs = explode(':', $m['crs']);
        $tileMatrixSet = 'custom' . $crs[1];
        
        $customtileMatrixSets .= $this->getTileMatrixSet($tileMatrixSet, $m['scales'], $m['bounds'], $m['crs']);
        
      } else {
        $tileMatrixSet = "GoogleMapsCompatible";
        
        list( $minx, $miny ) = $mercator->LatLonToMeters($bounds[1], $bounds[0]);
        list( $maxx, $maxy ) = $mercator->LatLonToMeters($bounds[3], $bounds[2]);
        $bounds3857 = array($minx, $miny, $maxx, $maxy);
      }
      $resourceUrlTemplate = $this->config['protocol'] . '://'
      . $this->config['baseUrls'][0] . '/wmts/' . $basename . '/{TileMatrixSet}/{TileMatrix}/{TileCol}/{TileRow}';
      if(strlen($format) <= 4){
        $resourceUrlTemplate .= '.' . $format;
      }
      echo'
    <Layer>
      <ows:Title>' . $title . '</ows:Title>
      <ows:Identifier>' . $basename . '</ows:Identifier>
      <ows:WGS84BoundingBox crs="urn:ogc:def:crs:OGC:2:84">
        <ows:LowerCorner>' . $bounds[0] . ' ' . $bounds[1] . '</ows:LowerCorner>
        <ows:UpperCorner>' . $bounds[2] . ' ' . $bounds[3] . '</ows:UpperCorner>
      </ows:WGS84BoundingBox>
      <Style isDefault="true">
        <ows:Identifier>default</ows:Identifier>
      </Style>
      <Format>' . $mime . '</Format>
      <TileMatrixSetLink>
        <TileMatrixSet>' . $tileMatrixSet . '</TileMatrixSet>
      </TileMatrixSetLink>
      <ResourceURL format="' . $mime . '" resourceType="tile" template="' . $resourceUrlTemplate . '"/>
    </Layer>';
    }
    // Print PseudoMercator TileMatrixSet
    echo $this->getMercatorTileMatrixSet();
    
    //Print wgs84 TileMatrixSet
    $matrixExtent = array(-180.000000, -90.000000, 180.000000, 90.000000);
    $scales = array(279541132.01435887813568115234, 139770566.00717943906784057617,
      69885283.00358971953392028809, 34942641.50179485976696014404, 17471320.75089742988348007202,
      8735660.37544871494174003601, 4367830.18772435747087001801, 2183915.09386217873543500900,
      1091957.54693108936771750450, 545978.77346554468385875225, 272989.38673277234192937613,
      136494.69336638617096468806, 68247.34668319308548234403, 34123.67334159654274117202,
      17061.83667079825318069197, 8530.91833539912659034599, 4265.45916769956329517299,
      2132.72958384978574031265);
    $crs = 'EPSG::4326';
    $matrixRatio = array(2, 1);

    echo $this->getTileMatrixSet($tileMatrixSet, $scales, $matrixExtent, $crs, $matrixRatio);
    
    //Print custom TileMatrixSet
    if (strlen($customtileMatrixSets) > 0) {
      echo $customtileMatrixSets; 
    }


  echo '</Contents>
  <ServiceMetadataURL xlink:href="' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/wmts/1.0.0/WMTSCapabilities.xml"/>
</Capabilities>';
  }

  /**
   * Returns tile via WMTS specification
   */
  public function getTile() {
    $request = $this->getGlobal('Request');
    if ($request) {
      if (strpos('/', $_GET['Format']) !== FALSE) {
        $format = explode('/', $_GET['Format']);
        $format = $format[1];
      } else {
        $format = $this->getGlobal('Format');
      }
      parent::renderTile(
              $this->getGlobal('Layer'),
              $this->getGlobal('TileMatrix'),
              $this->getGlobal('TileRow'),
              $this->getGlobal('TileCol'),
              $format
              );
    } else {
      parent::renderTile($this->layer, $this->z, $this->y, $this->x, $this->ext);
    }
  }

}

/**
 * Tile map service
 */
class Tms extends Server {

  /**
   * @param array $params
   */
  public $layer;

  /**
   * @var integer
   */
  public $z;

  /**
   * @var integer
   */
  public $y;

  /**
   * @var integer
   */
  public $x;

  /**
   * @var string
   */
  public $ext;

  /**
   *
   * @param array $params
   */
  public function __construct($params) {
    parent::__construct();
    parent::setParams($params);
  }

  /**
   * Returns getCapabilities metadata request
   */
  public function getCapabilities() {
    parent::setDatasets();
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    header("Content-type: application/xml");
    echo'<TileMapService version="1.0.0"><TileMaps>';
    foreach ($maps as $m) {
      $basename = $m['basename'];
      $title = (array_key_exists('name', $m) ) ? $m['name'] : $basename;
      $profile = $m['profile'];
      if ($profile == 'geodetic') {
        $srs = "EPSG:4326";
      } else {
        $srs = "EPSG:3857";
        echo '<TileMap title="' . $title . '" srs="' . $srs
        . '" type="InvertedTMS" ' . 'profile="global-' . $profile
        . '" href="' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/tms/' . $basename . '" />';
      }
    }
    echo '</TileMaps></TileMapService>';
  }

  /**
   * Prints metadata about layer
   */
  public function getLayerCapabilities() {
    parent::setDatasets();
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    foreach ($maps as $map) {
      if (strpos($map['basename'], $this->layer) !== false) {
        $m = $map;
        break;
      }
    }
    $title = (array_key_exists('name', $m)) ? $m['name'] : $m['basename'];
    $description = (array_key_exists('description', $m)) ? $m['description'] : "";
    $bounds = $m['bounds'];
    if ($m['profile'] == 'geodetic') {
      $srs = "EPSG:4326";
      $originx = -180.0;
      $originy = -90.0;
      $initialResolution = 0.703125;
    } else {
      $srs = "EPSG:3857";
      $originx = -20037508.342789;
      $originy = -20037508.342789;
      $mercator = new GlobalMercator();
      list( $minx, $miny ) = $mercator->LatLonToMeters($bounds[1], $bounds[0]);
      list( $maxx, $maxy ) = $mercator->LatLonToMeters($bounds[3], $bounds[2]);
      $bounds = array($minx, $miny, $maxx, $maxy);
      $initialResolution = 156543.03392804062;
    }
    $mime = ($m['format'] == 'jpg') ? 'image/jpeg' : 'image/png';
    header("Content-type: application/xml");
    echo '<TileMap version="1.0.0" tilemapservice="' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/' . $m['basename'] . '" type="InvertedTMS">
  <Title>' . htmlspecialchars($title) . '</Title>
  <Abstract>' . htmlspecialchars($description) . '</Abstract>
  <SRS>' . $srs . '</SRS>
  <BoundingBox minx="' . $bounds[0] . '" miny="' . $bounds[1] . '" maxx="' . $bounds[2] . '" maxy="' . $bounds[3] . '" />
  <Origin x="' . $originx . '" y="' . $originy . '"/>
  <TileFormat width="256" height="256" mime-type="' . $mime . '" extension="' . $m['format'] . '"/>
  <TileSets profile="global-' . $m['profile'] . '">';
    for ($zoom = $m['minzoom']; $zoom < $m['maxzoom'] + 1; $zoom++) {
      echo '<TileSet href="' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/' . $m['basename'] . '/' . $zoom . '" units-per-pixel="' . $initialResolution / pow(2, $zoom) . '" order="' . $zoom . '" />';
    }
    echo'</TileSets></TileMap>';
  }

  /**
   * Process getTile request
   */
  public function getTile() {
    parent::renderTile($this->layer, $this->z, $this->y, $this->x, $this->ext);
  }

}

/*
  GlobalMapTiles - part of Aggregate Map Tools
  Version 1.0
  Copyright (c) 2009 The Bivings Group
  All rights reserved.
  Author: John Bafford

  http://www.bivings.com/
  http://bafford.com/softare/aggregate-map-tools/

  Based on GDAL2Tiles / globalmaptiles.py
  Original python version Copyright (c) 2008 Klokan Petr Pridal. All rights reserved.
  http://www.klokan.cz/projects/gdal2tiles/

  Permission is hereby granted, free of charge, to any person obtaining a
  copy of this software and associated documentation files (the "Software"),
  to deal in the Software without restriction, including without limitation
  the rights to use, copy, modify, merge, publish, distribute, sublic ense,
  and/or sell copies of the Software, and to permit persons to whom the
  Software is furnished to do so, subject to the following conditions:

  The abov
  e copyright notice and this permission notice shall be included
  in all copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
  OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
  THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
  FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
  DEALINGS IN THE SOFTWARE.
 */

class GlobalMercator {

  var $tileSize;
  var $initialResolution;
  var $originShift;

//Initialize the TMS Global Mercator pyramid
  function __construct($tileSize = 256) {
    $this->tileSize = $tileSize;
    $this->initialResolution = 2 * M_PI * 6378137 / $this->tileSize;
# 156543.03392804062 for tileSize 256 Pixels
    $this->originShift = 2 * M_PI * 6378137 / 2.0;
# 20037508.342789244
  }

//Converts given lat/lon in WGS84 Datum to XY in Spherical Mercator EPSG:900913
  function LatLonToMeters($lat, $lon) {
    $mx = $lon * $this->originShift / 180.0;
    $my = log(tan((90 + $lat) * M_PI / 360.0)) / (M_PI / 180.0);

    $my *= $this->originShift / 180.0;

    return array($mx, $my);
  }

//Converts XY point from Spherical Mercator EPSG:900913 to lat/lon in WGS84 Datum
  function MetersToLatLon($mx, $my) {
    $lon = ($mx / $this->originShift) * 180.0;
    $lat = ($my / $this->originShift) * 180.0;

    $lat = 180 / M_PI * (2 * atan(exp($lat * M_PI / 180.0)) - M_PI / 2.0);

    return array($lat, $lon);
  }

//Converts pixel coordinates in given zoom level of pyramid to EPSG:900913
  function PixelsToMeters($px, $py, $zoom) {
    $res = $this->Resolution($zoom);
    $mx = $px * $res - $this->originShift;
    $my = $py * $res - $this->originShift;

    return array($mx, $my);
  }

//Converts EPSG:900913 to pyramid pixel coordinates in given zoom level
  function MetersToPixels($mx, $my, $zoom) {
    $res = $this->Resolution($zoom);

    $px = ($mx + $this->originShift) / $res;
    $py = ($my + $this->originShift) / $res;

    return array($px, $py);
  }

//Returns a tile covering region in given pixel coordinates
  function PixelsToTile($px, $py) {
    $tx = ceil($px / $this->tileSize) - 1;
    $ty = ceil($py / $this->tileSize) - 1;

    return array($tx, $ty);
  }

//Returns tile for given mercator coordinates
  function MetersToTile($mx, $my, $zoom) {
    list($px, $py) = $this->MetersToPixels($mx, $my, $zoom);

    return $this->PixelsToTile($px, $py);
  }

//Returns bounds of the given tile in EPSG:900913 coordinates
  function TileBounds($tx, $ty, $zoom) {
    list($minx, $miny) = $this->PixelsToMeters($tx * $this->tileSize, $ty * $this->tileSize, $zoom);
    list($maxx, $maxy) = $this->PixelsToMeters(($tx + 1) * $this->tileSize, ($ty + 1) * $this->tileSize, $zoom);

    return array($minx, $miny, $maxx, $maxy);
  }

//Returns bounds of the given tile in latutude/longitude using WGS84 datum
  function TileLatLonBounds($tx, $ty, $zoom) {
    $bounds = $this->TileBounds($tx, $ty, $zoom);

    list($minLat, $minLon) = $this->MetersToLatLon($bounds[0], $bounds[1]);
    list($maxLat, $maxLon) = $this->MetersToLatLon($bounds[2], $bounds[3]);

    return array($minLat, $minLon, $maxLat, $maxLon);
  }

//Resolution (meters/pixel) for given zoom level (measured at Equator)
  function Resolution($zoom) {
    return $this->initialResolution / (1 < $zoom);
  }

}

/**
 * Simple router
 */
class Router {

  /**
   * @param array $routes
   */
  public static function serve($routes) {
    $request_method = strtolower($_SERVER['REQUEST_METHOD']);
    $path_info = '/';
	global $config;
	$config['protocol'] = ( isset($_SERVER["HTTPS"]) or $_SERVER['SERVER_PORT'] == '443') ? "https" : "http";
    if (!empty($_SERVER['PATH_INFO'])) {
      $path_info = $_SERVER['PATH_INFO'];
    } else if (!empty($_SERVER['ORIG_PATH_INFO']) && $_SERVER['ORIG_PATH_INFO'] !== '/tileserver.php') {
      $path_info = $_SERVER['ORIG_PATH_INFO'];
    } else if (!empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/tileserver.php') !== false) {
      $path_info = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
      $config['baseUrls'][0] = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . '?';
    } else {
      if (!empty($_SERVER['REQUEST_URI'])) {
        $path_info = (strpos($_SERVER['REQUEST_URI'], '?') > 0) ? strstr($_SERVER['REQUEST_URI'], '?', true) : $_SERVER['REQUEST_URI'];
      }
    }
    $discovered_handler = null;
    $regex_matches = array();

    if ($routes) {
      $tokens = array(
          ':string' => '([a-zA-Z]+)',
          ':number' => '([0-9]+)',
          ':alpha' => '([a-zA-Z0-9-_@\.]+)'
      );
      //global $config;
      foreach ($routes as $pattern => $handler_name) {
        $pattern = strtr($pattern, $tokens);
        if (preg_match('#/?' . $pattern . '/?$#', $path_info, $matches)) {
          if (!isset($config['baseUrls'])) {
            $config['baseUrls'][0] = $_SERVER['HTTP_HOST'] . preg_replace('#/?' . $pattern . '/?$#', '', $path_info);
          }
          $discovered_handler = $handler_name;
          $regex_matches = $matches;
          break;
        }
      }
    }
    $handler_instance = null;
    if ($discovered_handler) {
      if (is_string($discovered_handler)) {
        if (strpos($discovered_handler, ':') !== false) {
          $discoverered_class = explode(':', $discovered_handler);
          $discoverered_method = explode(':', $discovered_handler);
          $handler_instance = new $discoverered_class[0]($regex_matches);
          call_user_func(array($handler_instance, $discoverered_method[1]));
        } else {
          $handler_instance = new $discovered_handler($regex_matches);
        }
      } elseif (is_callable($discovered_handler)) {
        $handler_instance = $discovered_handler();
      }
    } else {
      if (!isset($config['baseUrls'][0])) {
        $config['baseUrls'][0] = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . '?';
      }
      if (strpos($_SERVER['REQUEST_URI'], '=') != FALSE) {
        $kvp = explode('=', $_SERVER['REQUEST_URI']);
        $_GET['callback'] = $kvp[1];
        $params[0] = 'index';
        $handler_instance = new Json($params);
        $handler_instance->getJson();
      }
      $handler_instance = new Server;
      $handler_instance->getHtml();
    }
  }

}
