<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');
/**
 * ResourceRegistry3
 * 
 * @package     RR3
 * @author      Middleware Team HEAnet 
 * @copyright   Copyright (c) 2012, HEAnet Limited (http://www.heanet.ie)
 * @license     MIT http://www.opensource.org/licenses/mit-license.php
 *  
 */

/**
 * Disco Class
 * 
 * @package     RR3
 * @author      Janusz Ulanowski <janusz.ulanowski@heanet.ie>
 */

class Disco extends MY_Controller {

    protected $logo_url;

    function __construct()
    {
        parent::__construct();
        parse_str($_SERVER['QUERY_STRING'], $_GET);
        $this->output->set_content_type('application/json');
    }

    function circle($entityId, $m = NULL)
    {
        if (!empty($m) && $m != 'metadata.json')
        {
            show_error('Request not allowed', 403);
        }
        if(!empty($_GET['callback']))
        {
           $call = $_GET['callback'];
        }
        if (!empty($call))
        {
            $call_array = explode("_", $call);
        }
        $this->logo_basepath = $this->config->item('rr_logouriprefix');
        $this->logo_baseurl = $this->config->item('rr_logobaseurl');
        if (empty($this->logo_baseurl))
        {
            $this->logo_baseurl = base_url();
        }
        $this->logo_url = $this->logo_baseurl . $this->logo_basepath;
        $data = array();
        $name = base64url_decode($entityId);
        $tmp = new models\Providers;
        $me = $tmp->getOneSpByEntityId($name);
        if (empty($me))
        {
            log_message('debug', 'Failed generating circle metadata for ' . $name);
            show_error('unknown provider', 404);
            return;
        }

        $p = new models\Providers;
        $p1 = $p->getCircleMembers($me);
        if (empty($p1))
        {
            show_error('empty', 404);
            return;
        }
        $output = array();
        $oi = 0;
        foreach ($p1->getValues() as $key2)
        {
            if ($key2->getAvailable() && ($key2->getType() == 'IDP' OR $key2->getType() == 'BOTH'))
            {
                $output[$oi]['entityID'] = $key2->getEntityId();
        //        $output[$oi]['country'] = 'IE';
                $entityname = $key2->getName();
                if(empty($entityname))
                {
                   $entityname = $key2->getEntityId();
                }
                $output[$oi]['title'] = $entityname;
                $extend = $key2->getExtendMetadata();
                $count_extend = count($extend);
                $e_extend = array();
                $logo_set = FALSE;
                $geo_set = FALSE;


                if ($count_extend > 0)
                {
                    foreach ($extend as $ex)
                    {
                        $e_aparent = $ex->getParent();
                        $e_namespace = $ex->getNamespace();
                        $e_element = $ex->getElement();
                        if ($e_namespace == 'mdui')
                        {
                            if ($e_element == 'GeolocationHint' && ($geo_set === FALSE))
                            {
                                $e_value = explode(',', $ex->getEvalue());
                                if (!array_key_exists('geo', $output[$oi]))
                                {
                                    $output[$oi]['geo'] = array('lat' => $e_value[0], 'lon' => $e_value[1]);
                                    $geo_set = true;
                                }
                            }
                            elseif ($e_element == 'Logo' && ($logo_set === FALSE))
                            {
                                $ElementValue = $ex->getEvalue();
                                $output[$oi]['icon'] = $ElementValue;
                                $logo_set = true;
                            }
                        }
                    }
                }


                $oi++;
            }
        }
        if (!empty($call_array) && is_array($call_array) && count($call_array) == 3 && $call_array['0'] == 'dj' && $call_array['1'] == 'md' && is_numeric($call_array['2']))
        {
            $jsonoutput = $call . '(' . json_encode($output) . ')';
        }
        else
        {
            $jsonoutput = json_encode($output);
        }
        $data['result'] = $jsonoutput;
        $this->load->view('disco_view',$data);
    }

}
