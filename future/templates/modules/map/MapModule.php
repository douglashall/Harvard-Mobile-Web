<?php

// TODO for this module:
// - terminology is bad:
//   * "category" and "layer" are used interchangeably when we
//      mean the thing that contains the image controller
//   * "selectvalues" is MIT legacy and we really mean a place identifier
// - need to write map image controllers in such a way that other modules
//   can incorporate map images without redoing work

require_once realpath(LIB_DIR.'/Module.php');
require_once realpath(LIB_DIR.'/MapLayerDataController.php');
require_once realpath(LIB_DIR.'/StaticMapImageController.php');
require_once realpath(LIB_DIR.'/MapSearch.php');

// detail-basic: $imageUrl $imageWidth $imageHeight $scrollNorth $scrollSouth $scrollEast $scrollWest $zoomInUrl $zoomOutUrl $photoUrl $photoWidth
// detail: $hasMap $mapPane $photoPane $detailPane $imageWidth $imageHeight $imageUrl $name $details
// search: $places

class MapModule extends Module {
    protected $id = 'map';
    protected $feeds;
    
    private function pageSupportsDynamicMap() {
        return $this->pagetype == 'compliant'
            && $this->platform != 'blackberry'
            && $this->platform != 'bbplus';
    }
    
    private function initializeMap(MapLayerDataController $layer, MapFeature $feature) {
        
        $style = $feature->getStyleAttribs();
        $style['title'] = $feature->getTitle(); // for annotations
        $geometry = $feature->getGeometry();

        // center
        if (isset($this->args['center'])) {
            $latlon = explode(",", $this->args['center']);
            $center = array('lat' => $latlon[0], 'lon' => $latlon[1]);
        } else {
            $center = $geometry->getCenterCoordinate();
        }

        // zoom
        if (isset($this->args['zoom'])) {
            $zoomLevel = $this->args['zoom'];
        } else {
            $zoomLevel = $layer->getDefaultZoomLevel();
        }

        // image size
        switch ($this->pagetype) {
            case 'compliant':
                if ($GLOBALS['deviceClassifier']->getPlatform() == 'bbplus') {
                    $imageWidth = 410; $imageHeight = 260;
                } else {
                    $imageWidth = 290; $imageHeight = 290;
                }
                break;
            case 'touch':
            case 'basic':
                $imageWidth = 200; $imageHeight = 200;
                break;
        }
        $this->assign('imageHeight', $imageHeight);
        $this->assign('imageWidth',  $imageWidth);

        $mapControllers = array();
        $mapControllers[] = $layer->getStaticMapController();
        if ($this->pageSupportsDynamicMap() && $layer->supportsDynamicMap()) {
            $mapControllers[] = $layer->getDynamicMapController();
        }

        foreach ($mapControllers as $mapController) {

            if ($mapController->supportsProjections()) {
                $mapController->setDataProjection($layer->getProjection());
            }
            
            $mapController->setCenter($center);
            $mapController->setZoomLevel($zoomLevel);

            switch ($geometry->getType()) {
                case 'Point':
                    if ($mapController->canAddAnnotations()) {
                        $mapController->addAnnotation($center['lat'], $center['lon'], $style);
                    }
                    break;
                case 'Polyline':
                    if ($mapController->canAddPaths()) {
                        $mapController->addPath($geometry->getPoints(), $style);
                    }
                    break;
                case 'Polygon':
                    if ($mapController->canAddPolygons()) {
                        $mapController->addPolygon($geometry->getRings(), $style);
                    }
                    break;
                default:
                    break;
            }

            $mapController->setImageWidth($imageWidth);
            $mapController->setImageHeight($imageHeight);

            if ($mapController->isStatic()) {

                $this->assign('imageUrl', $mapController->getImageURL());

                $this->assign('scrollNorth', $this->detailUrlForPan('n', $mapController));
                $this->assign('scrollEast', $this->detailUrlForPan('e', $mapController));
                $this->assign('scrollSouth', $this->detailUrlForPan('s', $mapController));
                $this->assign('scrollWest', $this->detailUrlForPan('w', $mapController));

                $this->assign('zoomInUrl', $this->detailUrlForZoom('in', $mapController));
                $this->assign('zoomOutUrl', $this->detailUrlForZoom('out', $mapController));

            } else {
                $mapController->setMapElement('mapimage');
                $this->addExternalJavascript($mapController->getIncludeScript());
                $this->addInlineJavascript($mapController->getHeaderScript());
                $this->addInlineJavascriptFooter($mapController->getFooterScript());
            }
        }
    }

    // TODO finish this
    private function initializeFullscreenMap() {
      $selectvalue = $this->args['selectvalues'];
    }

    // TODO reimplement this for MIT-style building number/name drilldown.
    // otherwise this funciton is not being used
  private function drillURL($drilldown, $name=NULL, $addBreadcrumb=true) {
    $args = array(
      'drilldown' => $drilldown,
    );
    if (isset($this->args['category'])) {
      $args['category'] = $this->args['category'];
    }
    if (isset($name)) {
      $args['desc'] = $name;
    }
    return $this->buildBreadcrumbURL('category', $args, $addBreadcrumb);
  }
  
  private function categoryURL($category=NULL, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('category', array(
      'category' => isset($category) ? $category : $_REQUEST['category'],
    ), $addBreadcrumb);
  }

  private function detailURL($name, $category, $info=null, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('detail', array(
      'selectvalues' => $name,
      'category'     => $category,
      'info'         => $info,
    ), $addBreadcrumb);
  }
  
  private function detailURLForFederatedSearchResult($result, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('detail', $this->detailURLArgsForResult($result), $addBreadcrumb);
  }
  
  private function detailURLForResult(/*$id, $category*/$urlArgs, $addBreadcrumb=true) {
    return $this->buildBreadcrumbURL('detail', $urlArgs, $addBreadcrumb);
  }
  
  private function detailUrlForPan($direction, $mapController) {
    $args = $this->args;
    $center = $mapController->getCenterForPanning($direction);
    $args['center'] = $center['lat'] .','. $center['lon'];
    return $this->buildBreadcrumbURL('detail', $args, false);
  }

  private function detailUrlForZoom($direction, $mapController) {
    $args = $this->args;
    $args['zoom'] = $mapController->getLevelForZooming($direction);
    return $this->buildBreadcrumbURL('detail', $args, false);
  }

  private function detailUrlForBBox($bbox=null) {
    $args = $this->args;
    if (isset($bbox)) {
      $args['bbox'] = $bbox;
    }
    return $this->buildBreadcrumbURL('detail', $args, false);
  }
  
  private function fullscreenUrlForBBox($bbox=null) {
    $args = $this->args;
    if (isset($bbox)) {
      $args['bbox'] = $bbox;
    }
    return $this->buildBreadcrumbURL('fullscreen', $args, false);
  }

  public function federatedSearch($searchTerms, $maxCount, &$results) {
    $mapSearchClass = $GLOBALS['siteConfig']->getVar('MAP_SEARCH_CLASS');
    $mapSearch = new $mapSearchClass();
    if (!$this->feeds)
        $this->feeds = $this->loadFeedData();
    $mapSearch->setFeedData($this->feeds);
    $searchResults = array_values($mapSearch->searchCampusMap($searchTerms));
    
    $limit = min($maxCount, count($searchResults));
    for ($i = 0; $i < $limit; $i++) {
      $result = array(
        'title' => $mapSearch->getTitleForSearchResult($searchResults[$i]),
        'url'   => $this->buildBreadcrumbURL("/{$this->id}/detail",
            $mapSearch->getURLArgsForSearchResult($searchResults[$i]), false),
      );
      $results[] = $result;
    }

    return count($searchResults);
  }

    private function getLayer($index) {
        if (isset($this->feeds[$index])) {
            $feedData = $this->feeds[$index];
            $controller = MapLayerDataController::factory($feedData);
            $controller->setDebugMode($GLOBALS['siteConfig']->getVar('DATA_DEBUG'));
            return $controller;
        }
    }

  protected function initializeForPage() {
    switch ($this->page) {
      case 'help':
        break;
        
      case 'index':
        if (!$this->feeds)
            $this->feeds = $this->loadFeedData();

        $categories = array();
        foreach ($this->feeds as $id => $feed) {
            if (isset($feed['HIDDEN']) && $feed['HIDDEN']) continue;
            $subtitle = isset($feed['SUBTITLE']) ? $feed['SUBTITLE'] : null;
            $categories[] = array(
                'title' => $feed['TITLE'],
                'subtitle' => $subtitle,
                'url' => $this->categoryURL($id),
                );
        }

        // TODO show category description in cell subtitles
        $this->assign('categories', $categories);
        break;
        
      case 'search':
      
        if (isset($this->args['filter'])) {
            $searchTerms = $this->args['filter'];

            // need more standardized var name for this config
            //$externalSearch = $GLOBALS['siteConfig']->getVar('MAP_SEARCH_URL');
            $mapSearchClass = $GLOBALS['siteConfig']->getVar('MAP_SEARCH_CLASS');
            $mapSearch = new $mapSearchClass();
            if (!$this->feeds)
                $this->feeds = $this->loadFeedData();
            $mapSearch->setFeedData($this->feeds);
            
            $searchResults = $mapSearch->searchCampusMap($searchTerms);

            if (count($searchResults) == 1) {
                $this->redirectTo('detail', $mapSearch->getURLArgsForSearchResult($searchResults[0]));
            } else {
                $places = array();
                foreach ($searchResults as $result) {
                    $title = $mapSearch->getTitleForSearchResult($result);
                    $place = array(
                        'title' => $title,
                        'subtitle' => $feature->getSubtitle(),
                        'url' => $this->detailURLForResult($mapSearch->getURLArgsForSearchResult($result)),
                    );
                    $places[] = $place;
                }
            }

            $this->assign('searchTerms', $searchTerms);
            $this->assign('places',      $places);
          
        } else {
          $this->redirectTo('index');
        }
        break;
        
      case 'category':
        if (isset($this->args['category'])) {
          $category = $this->args['category'];

          if (!$this->feeds)
              $this->feeds = $this->loadFeedData();

          $categories = array();
          foreach ($this->feeds as $id => $feed) {
              $categories[] = array(
                  'id' => $id,
                  'title' => $feed['TITLE'],
                  );
          }

          $layer = $this->getLayer($category);
          
          // TODO some categories have subcategories
          // they will return lists of categories instead of lists of features
          
          $features = $layer->getFeatureList();

          $places = array();
          foreach ($features as $feature) {
            $places[] = array(
              'title' => $feature->getTitle(),
              'subtitle' => $feature->getSubtitle(),
              'url'   => $this->detailURL($feature->getIndex(), $category),
              );
          }
          $this->assign('title',      $layer->getTitle());
          $this->assign('places',     $places);          
          $this->assign('categories', $categories);
          
        } else {
          $this->redirectTo('index');
        }
        break;
      
      case 'detail':
        $detailConfig = $this->loadWebAppConfigFile('map-detail', 'detailConfig');        
        $tabKeys = array();
        $tabJavascripts = array();
        
        // Map Tab
        $tabKeys[] = 'map';

        $hasMap = true;
        $this->assign('hasMap', $hasMap);
        if (!$this->feeds)
            $this->feeds = $this->loadFeedData();

        $index = $this->args['selectvalues'];
        $layer = $this->getLayer($this->args['category']);
        $feature = $layer->getFeature($index);
        $this->initializeMap($layer, $feature);

        $this->assign('name', $feature->getTitle());
        $this->assign('address', $feature->getSubtitle());

        // Photo Tab
        $photoServer = $GLOBALS['siteConfig']->getVar('MAP_PHOTO_SERVER');
        // this method of getting photo url is harvard-specific and
        // further only works on data for ArcGIS features.
        // TODO allow map controllers to determine what to put in the tabs
        if ($photoServer) {
            $photoFile = $feature->getField('Photo');
            if (isset($photoFile) && $photoFile != 'Null') {
                $tabKeys[] = 'photo';
                $tabJavascripts['photo'] = "loadPhoto(photoURL,'photo');";
                $photoUrl = $photoServer.$photoFile;
                $this->assign('photoUrl', $photoUrl);
                $this->addInlineJavascript("var photoURL = '{$photoUrl}';");
            }
        }
        
        // Details Tab
        $tabKeys[] = 'detail';
        if (is_subclass_of($layer, 'ArcGISDataController')) {
            $feature->setBlackList($detailConfig['details']['suppress']);
        }
        
        $displayDetailsAsList = $feature->getDescriptionType() == MapFeature::DESCRIPTION_LIST;
        $this->assign('displayDetailsAsList', $displayDetailsAsList);
        $this->assign('details', $feature->getDescription());

        $this->enableTabs($tabKeys, null, $tabJavascripts);
        break;
        
      case 'fullscreen':
        $this->initializeFullscreenMap();
        break;
    }
  }
}
