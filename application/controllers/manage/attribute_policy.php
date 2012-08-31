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
 * Attribute_policy Class
 * 
 * @package     RR3
 * @author      Janusz Ulanowski <janusz.ulanowski@heanet.ie>
 */

class Attribute_policy extends MY_Controller {

    protected $tmp_providers;
    protected $attributes;
    protected $tmp_attrs;
    protected $tmp_arps;

    public function __construct() {
        parent::__construct();
        $loggedin = $this->j_auth->logged_in();
        $this->current_site = current_url();
        if (!$loggedin) {
            $this->session->set_flashdata('target', $this->current_site);
            redirect('auth/login', 'refresh');
        }

        $this->current_idp = $this->session->userdata('current_idp');
        $this->current_idp_name = $this->session->userdata('current_idp_name');
        $this->current_sp = $this->session->userdata('current_sp');
        $this->current_sp_name = $this->session->userdata('current_sp_name');

        $this->load->helper('form');
        $this->load->library(array('table', 'form_element'));
        $this->tmp_providers = new models\Providers;
        $this->tmp_arps = new models\AttributeReleasePolicies;
        $this->tmp_attrs = new models\Attributes;
        $this->attributes = $this->tmp_attrs->getAttributes();
        $this->load->library('zacl');
    }

    private function display_default_policy($idp) {
        $this->load->library('show_element');
        $result = $this->show_element->generateTableDefaultArp($idp);
        return $result.'<br />';
    }

    private function display_specific_policy($idp) {
        $this->load->library('show_element');
        $result = $this->show_element->generateTableSpecificArp($idp);
        return $result;
    }

    private function display_federations_policy($idp) {
        $this->load->library('show_element');
        $result = $this->show_element->generateTableFederationsArp($idp);
        return $result.'<br />';
    }

    public function submit_global() {

        /**
         * @todo add validate submit
         */
        $idpid = $this->input->post('idpid');
        $attr = $this->input->post('attribute');
        $policy = $this->input->post('policy');
        $action = $this->input->post('submit');
        $is_policy = false;
        if (($policy == 0) or ($policy == 1) or ($policy == 2)) {
            $is_policy = true;
        }
        if (empty($idpid) or !is_numeric($idpid)) {
            show_error('something went wrong', 503);
        }
        $resource = $idpid;
        $group = 'idp';
        $has_write_access = $this->zacl->check_acl($resource, 'write', $group, '');
        if (!$has_write_access) {
            $data['content_view'] = 'nopermission';
            $data['error'] = "No access to edit idp";
            $this->load->view('page', $data);
            return;
        }



        if (!$is_policy) {
            log_message('error', $this->mid . 'Policy wasnt set');
            show_error($this->mid . 'Policy is not set', 503);
        }

        $attrPolicy = $this->tmp_arps->getOneGlobalPolicy($idpid, $attr);
        if (empty($attrPolicy) && ($action == 'modify' or $action == 'Add default policy')) {
            $attrPolicy = new models\AttributeReleasePolicy;
            $attribute = $this->tmp_attrs->getAttributeById($attr);

            $idp = $this->tmp_providers->getOneIdpById($idpid);
            if (empty($idp) or empty($attribute)) {
                log_message('debug', $this->mid . 'Cannot create new policy for idpid = ' . $idpid . ' because idp attribute not found');
                show_error($this->mid . 'No attribute or Identity Provider', 503);
            }
            $attrPolicy->setGlobalPolicy($idp, $attribute, $policy);
        } elseif ($action == 'Cancel') {
            return $this->globals($idpid);
        } else {
            $attrPolicy->setPolicy($policy);
        }



        if ($action == 'modify' or $action == 'Add default policy') {
            $this->em->persist($attrPolicy);
        } elseif ($action == 'delete' && !empty($attrPolicy)) {
            $this->em->remove($attrPolicy);
        }

	
        $this->j_cache->library('arp_generator', 'arpToArray', array($idpid),-1);
        $this->em->flush();
        return $this->globals($idpid);
    }

    /**
     * for global policy requester should be set to 0
     */
    public function detail($idp_id, $attr_id, $type, $requester) {
        $can_proceed = false;
        $data = array();
        $subtitle = "";

        if (!is_numeric($idp_id) or !is_numeric($attr_id)) {
            log_message('error', $this->mid . "Idp id or attr id is set incorectly");
            show_error($this->mid . 'error', 404);
        }
        if (!($type == 'global' or $type == 'fed' or $type == 'sp')) {
            log_message('error', $this->mid . "The type of policy is: " . $type . ". Should be one of: global,fed,sp");
            show_error($this->mid . 'Wrong type of policy', 404);
        }

        $idp = $this->tmp_providers->getOneIdPById($idp_id);
        if (empty($idp)) {
            log_message('error', $this->mid . 'IdP not found with id:' . $idp_id);
            show_error($this->mid . 'Identity Provider not found with id:' . $idp_id);
        }
        $resource = $idp->getId();
        $group = 'idp';
        $has_write_access = $this->zacl->check_acl($resource, 'write', $group, '');
        if (!$has_write_access) {
            $data['content_view'] = 'nopermission';
            $data['error'] = "No access to edit idp";
            $this->load->view('page', $data);
            return;
        }

        $attribute = $this->tmp_attrs->getAttributeById($attr_id);
        if (empty($attribute)) {
            log_message('error', $this->mid . 'Attribute not found with id:' . $attr_id);
            show_error($this->mid . 'Attribute not found with id:' . $attr_id);
        }


        $action = '';
        if ($type == 'global') {
            $attr_policy = $this->tmp_arps->getOneGlobalPolicy($idp_id, $attr_id);
            $action = base_url('manage/attribute_policy/submit_global');
            $subtitle = "Default attribute release policy";
        } elseif ($type == 'fed') {
            $attr_policy = $this->tmp_arps->getOneFedPolicy($idp_id, $attr_id, $requester);
            $tmp_feds = new models\Federations;
            $fed = $tmp_feds->getOneFederationById($requester);
            if (!empty($fed)) {
                $data['fed_name'] = $fed->getName();
                $data['fed_url'] = base64url_encode($fed->getName());
            }
            $action = base_url('manage/attribute_policy/submit_fed/' . $idp_id);
            $subtitle = "Attribute release policy for  federation";
        } elseif ($type == 'sp') {
            $attr_policy = $this->tmp_arps->getOneSPPolicy($idp_id, $attr_id, $requester);

            $sp = $this->tmp_providers->getOneSpById($requester);
            if (!empty($sp)) {
                log_message('debug', $this->mid . 'SP found with id: ' . $requester);
                $data['sp_name'] = $sp->getName();
            } else {
                log_message('debug', $this->mid . 'SP not found with id: ' . $requester);
                show_error($this->mid . ' Service Provider not found for id:' . $requester, 404);
            }
            $action = base_url('manage/attribute_policy/submit_sp/' . $idp_id);
            $subtitle = "Specific attribute release policy for service provider";
        }
        if (empty($attr_policy)) {
            $data['error_message'] = $this->mid . ' Attribute Release Policy not found';
            $message = $this->mid;
            $message .= 'Policy not found for: ';
            $message .= '[idp_id=' . $idp_id . ', attr_id=' . $attr_id . ', type=' . $type . ', requester=' . $requester . ']';
            log_message('debug', $message);
            $data['attribute_name'] = $attribute->getName();
            $data['idp_name'] = $idp->getName();
            $data['idp_id'] = $idp_id;
            $data['requester_id'] = $requester;
            $data['type'] = $type;
            $narp = new models\AttributeReleasePolicy;
            $narp->setProvider($idp);
            $narp->setAttribute($attribute);
            $narp->setType('sp');
            $narp->setRequester($sp->getId());
            $narp->setPolicy(0);
            $submit_type = 'create';
            log_message('debug', 'KKK:::' . $submit_type);
            $data['edit_form'] = $this->form_element->generateEditPolicyForm($narp, $action, $submit_type);
        } else {
            log_message('debug', $this->mid . 'Policy has been found for: [idp_id=' . $idp_id . ', attr_id=' . $attr_id . ', type=' . $type . ', requester=' . $requester . ']');
            $data['attribute_name'] = $attribute->getName();
            $data['idp_name'] = $idp->getName();
            $data['idp_id'] = $idp->getId();
            $data['requester_id'] = $requester;
            $data['type'] = $type;
            $submit_type = 'modify';
            $data['edit_form'] = $this->form_element->generateEditPolicyForm($attr_policy, $action, $submit_type);
        }

        $data['subtitle'] = $subtitle;
        $data['content_view'] = 'manage/attribute_policy_detail_view';
        $this->load->view('page', $data);
    }

    public function globals($idp_id) {
        $data = array();
        $data['content_view'] = 'manage/attribute_policy_view';

        $this->title = 'Attribute Release Policy';
        if (!is_numeric($idp_id)) {
            if (empty($this->current_idp)) {
                $this->session->set_flashdata('target', $this->current_site);
                redirect('manage/settings/idp', 'refresh');
            }
            $search_idp = $this->current_idp;
        } else {
            $search_idp = $idp_id;
        }
        /**
         * finding idp by id 
         */
        $idp = $this->tmp_providers->getOneIdpById($search_idp);

        /**
         * display 404 if idp not found 
         */
        if (empty($idp)) {
            log_message('debug', $this->mid . 'Identity Provider with id ' . $idp . ' not found');
            show_error($this->mid . 'Identity Provider not found', 404);
            return;
        }
        $resource = $idp->getId();
        $group = 'idp';
        $has_write_access = $this->zacl->check_acl($resource, 'write', $group, '');
        if (!$has_write_access) {
            $data['content_view'] = 'nopermission';
            $data['error'] = "No access to edit idp";
            $this->load->view('page', $data);
            return;
        }
        /**
         * pull default arp - it's equal to supported attributes 
         */
        $data['default_policy'] = $this->display_default_policy($idp);

        $data['federations_policy'] = $this->display_federations_policy($idp);

        $data['specific_policy'] = $this->display_specific_policy($idp);

        /**
         * pull all attributes defitnitions 
         */
        $attrs = $this->tmp_attrs->getAttributes();
        $attrs_sarray = array();
        foreach ($attrs as $a) {
            $attrs_sarray[$a->getId()] = $a->getName();
        }

        /**
         * @todo change to remove double mysql request (below the same to  $this->display_default_policy($idp) 
         */
        //$tmp = new models\Arps;


        $supportedAttrs = $this->tmp_arps->getSupportedAttributes($idp);
        $supportedArray = array();
        foreach ($supportedAttrs as $s) {
            $supportedArray[$s->getAttribute()->getId()] = $s->getAttribute()->getName();
        }

        $existingAttrs = $this->tmp_arps->getGlobalPolicyAttributes($idp);
        $globalArray = array();
        if (!empty($existingAttrs)) {
            foreach ($existingAttrs as $e) {
                $globalArray[$e->getAttribute()->getId()] = $e->getAttribute()->getName();
            }
        }


        /**
         * array of attributes wchich dont exist in arp yet
         */
        $attrs_array_newform = array_diff_key($supportedArray, $globalArray);
        $data['attrs_array_newform'] = $attrs_array_newform;
        $data['spid'] = null;

        $data['formdown'][''] = 'Select one ...';
        $sps = $this->tmp_providers->getCircleMembersSP($idp);
        foreach ($sps as $key) {
            $data['formdown'][$key->getId()] = $key->getName() . ' (' . $key->getEntityId() . ')';
        }

        $data['idpid'] = $search_idp;
        $data['idp_name'] = $idp->getName();
        $data['idp_entityid'] = $idp->getEntityId();


        $this->load->view('page', $data);
    }

    public function show_feds($idp_id, $fed_id = null) {
        $idp = $this->tmp_providers->getOneIdpById($idp_id);
        if (empty($idp)) {
            show_error($this->mid . 'Identity Provider not found', 404);
        }
        $resource = $idp->getId();
        $group = 'idp';
        $has_write_access = $this->zacl->check_acl($resource, 'write', $group, '');
        if (!$has_write_access) {
            $data['content_view'] = 'nopermission';
            $data['error'] = "No access to edit idp";
            $this->load->view('page', $data);
            return;
        }

        if (($this->input->post('fedid')) && empty($fed_id)) {
            redirect(base_url('manage/attribute_policy/show_feds/' . $idp_id . '/' . $this->input->post('fedid')), 'refresh');
        } elseif (empty($fed_id)) {
            $data = array();

            $feds = $idp->getFederations();
            $data['federations'] = $this->form_element->generateFederationsElement($feds);
            $data['idpid'] = $idp->getId();
            $data['idpname'] = $idp->getName();
            $data['content_view'] = 'manage/attribute_policy_feds_view';
            $this->load->view('page', $data);
        } else {
            $data = array();

            $tmp_fed = new models\Federations();
            $fed = $tmp_fed->getOneFederationById($fed_id);
            if (empty($fed)) {
                return $this->show_feds($idp->getId());
            }
            /**
             * getting supported attrs
             */
            $supportedAttrs = $this->tmp_arps->getSupportedAttributes($idp);


            /**
             * getting set arp for this fed
             */
            $existingAttrs = $this->tmp_arps->getFedPolicyAttributesByFed($idp, $fed);

            /**
             * build array
             */
            if (empty($supportedAttrs)) {
                show_error($this->mid . "you need to set supported attributes first", 404);
            }
            $attrs_tmp = array();
            $attrs = array();
            foreach ($supportedAttrs as $s) {
                $attrs[$s->getAttribute()->getId()][$fed->getId()] = 100;
                $attrs[$s->getAttribute()->getId()]['name'] = $s->getAttribute()->getName();
            }

            if (!empty($existingAttrs)) {
                foreach ($existingAttrs as $e) {
                    $attrs_tmp[$e->getAttribute()->getId()][$e->getRequester()] = $e->getPolicy();
                }
            }
            $attrs_merged = array_replace_recursive($attrs, $attrs_tmp);

            $tbl_array = array();


            $i = 0;
            foreach ($attrs_merged as $key => $value) {
                $policy = $value[$fed->getId()];
                $col2 = form_dropdown('attrid[' . $key . ']', array('100' => 'not set', '0' => 'never permit', '1' => 'permit only if required', '2' => 'permit if required or desired'), $policy);
                $tbl_array[$i] = array($value['name'], $col2);
                $i++;
            }
     //       $submit_buttons_row = '<span style="white-space: nowrap;">' . form_submit('submit', 'delete all') . form_submit('submit', 'modify') . '</span>';
            $submit_buttons_row = '<span class="buttons"><button name="submit" value="delete all"  class="btn negative"><span class="cancel">delete all</button><button><span type="submit" name="submit" value="modify" class="save">modify</span></button></span>';
            $tbl_array[$i] = array('data' => array('data' => $submit_buttons_row, 'colspan' => 2));
            $data['tbl_array'] = $tbl_array;
            $data['fedid'] = $fed->getId();
            $data['idpid'] = $idp->getId();
            $data['caption'] = $idp->getName() . "<br /><br />Attribute Release Policy for federation: " . $fed->getName();

            $data['content_view'] = 'manage/attribute_policy_form_for_fed_view';
            $this->load->view('page', $data);
        }
    }

    public function submit_fed($idp_id) {
        log_message('debug', $this->mid . 'submit_fed submited');
        $idpid = $this->input->post('idpid');
        $fedid = $this->input->post('fedid');
        if (!empty($idpid) && !empty($fedid)) {
            if ($idp_id === $idpid) {
                log_message('debug', $this->mid . 'idpid is OK: ' . $idpid);


                $tmp_feds = new models\Federations;

                $idp = $this->tmp_providers->getOneIdpById($idpid);
                if (empty($idp)) {
                    log_message('error', $this->mid . 'Form attribute_policy for fed. IdP not found with id: ' . $this->input->post('idpid'));
                    show_error('IdP not found', 404);
                } else {
                    log_message('debug', $this->mid . 'IDP found with id: ' . $idpid);
                }

                $resource = $idp->getId();
                $group = 'idp';
                $has_write_access = $this->zacl->check_acl($resource, 'write', $group, '');
                if (!$has_write_access) {
                    $data['content_view'] = 'nopermission';
                    $data['error'] = "No access to edit idp";
                    $this->load->view('page', $data);
                    return;
                }

                $fed = $tmp_feds->getOneFederationById($fedid);
                if (empty($fed)) {
                    log_message('error', $this->mid . 'Form attribute_policy for fed. Federation not found with id: ' . $this->input->post('fedid'));
                    show_error('federation not found', 404);
                } else {
                    log_message('debug', $this->mid . 'Federation found with id: ' . $fedid);
                }

                $attrlist = $this->input->post('attrid');
                $attribute = $this->input->post('attribute');

                if (!empty($attrlist) && is_array($attrlist) && count($attrlist) > 0) {
                    $submit_action = $this->input->post('submit');
                    log_message('debug', $this->mid . 'Found attributes');
                    foreach ($attrlist as $key => $value) {
                        $attr_pol = $this->tmp_arps->getOneFedPolicyAttribute($idp, $fed, $key);
                        if (empty($attr_pol) && ($value != '100')) {
                            $attr_pol = new models\AttributeReleasePolicy;
                            $attr_pol->setProvider($idp);
                            $tmp_attrs = new models\Attributes;
                            $attribute = $tmp_attrs->getAttributeById($key);
                            $attr_pol->setAttribute($attribute);
                            $attr_pol->setType('fed');
                            $attr_pol->setRequester($fed->getId());
                            $attr_pol->setPolicy($value);
                            $this->em->persist($attr_pol);
                        } elseif (!empty($attr_pol)) {
                            if ($value == '100' or $submit_action == 'delete all') {
                                $this->em->remove($attr_pol);
                            } else {
                                $attr_pol->setPolicy($value);
                                $this->em->persist($attr_pol);
                            }
                        }
                    }
                    $this->em->flush();
                    return $this->globals($idpid);
                } elseif (!empty($attribute) && is_numeric($attribute)) {
                    $submit_action = $this->input->post('submit');
                    $policy = $this->input->post('policy');
                    log_message('debug', $this->mid . "Found numeric attr id: " . $attribute);
                    $attr_pol = $this->tmp_arps->getOneFedPolicyAttribute($idp, $fed, $attribute);
                    if (empty($attr_pol)) {
                        log_message('debug', $this->mid . 'Attribute policy not found with idp:' . $idp->getId() . ' fed:' . $fed->getId() . ' attr:' . $attribute);
                    } else {
                        log_message('debug', $this->mid . 'Found attribute policy idp:' . $idp->getId() . ' fed:' . $fed->getId() . ' attr:' . $attribute);
                        if ($policy == '100' or $submit_action == 'delete') {
                            $this->em->remove($attr_pol);
                        } else {
                            $attr_pol->setPolicy($policy);
                            $this->em->persist($attr_pol);
                        }
                    }
                    $this->em->flush();
                    return $this->globals($idp_id);
                }
            } else {
                log_message('error', 'Id of idp in form is different than post-target idp id');
                show_error($this->mid . 'POST target doesnt match attrs', 503);
            }
        }
    }

    public function submit_sp($idp_id) {
        log_message('debug', $this->mid . 'submit_sp submited');
        $idpid = $this->input->post('idpid');
        $spid = $this->input->post('requester');
        $attributeid = $this->input->post('attribute');
        $policy = $this->input->post('policy');
        $action = $this->input->post('submit');
        if (empty($spid) or !is_numeric($spid)) {
            log_message('error', $this->mid . 'spid in post not provided or not numeric');
            show_error($this->mid . 'Missed informations in post ', 404);
        }
        if (empty($idpid) or !is_numeric($idpid)) {
            log_message('error', $this->mid . 'idpid in post not provided or not numeric');
            show_error($this->mid . 'Missed informations in post ', 404);
        }
        if (empty($attributeid) or !is_numeric($attributeid)) {
            log_message('error', $this->mid . 'attributeid in post not provided or not numeric');
            show_error($this->mid . 'Missed informations in post ', 404);
        }
        if (!isset($policy) or !is_numeric($policy)) {
            log_message('error', $this->mid . 'policy in post not provided or not numeric:' . $policy);
            show_error($this->mid . 'Missed informations in post ', 404);
        }
        if (!($policy == 0 or $policy == 1 or $policy == 2 or $policy == 100)) {
            log_message('error', $this->mid . 'wrong policy in post: ' . $policy);
            show_error($this->mid . 'Wrong policy value ', 404);
        }
        if ($idp_id != $idpid) {
            log_message('error', $this->mid . 'idp id from post is not equal with idp in url, idp in post:' . $idpid . ', idp in url:' . $idp_id);
            show_error($this->mid . 'Wrong post target for requested idp modification ', 404);
        }

        $sp = $this->tmp_providers->getOneSpById($spid);
        if (empty($sp)) {
            log_message('error', $this->mid . 'SP with id ' . $spid . ' doesnt exist');
            show_error($this->mid . 'Service Provider doesnt exist ', 404);
        }
        $idp = $this->tmp_providers->getOneIdpById($idp_id);
        if (empty($idp)) {
            log_message('error', $this->mid . 'IDP with id ' . $idp_id . ' doesnt exist');
            show_error($this->mid . 'Identity Provider doesnt exist ', 404);
        }
        $resource = $idp->getId();
        $group = 'idp';
        $has_write_access = $this->zacl->check_acl($resource, 'write', $group, '');
        if (!$has_write_access) {
            $data['content_view'] = 'nopermission';
            $data['error'] = "No access to edit idp";
            $this->load->view('page', $data);
            return;
        }

        $tmp_attrs = new models\Attributes;
        $attribute = $tmp_attrs->getAttributeById($attributeid);
        if (empty($attribute)) {
            log_message('error', $this->mid . 'attribute  with id ' . $idp_id . ' doesnt exist');
            show_error($this->mid . 'Attribute you requested to change doesnt exist ', 404);
        }
        log_message('debug', $this->mid . 'Arguments passed correctly in form');
        log_message('debug', $this->mid . 'Arguments passed: idp_id:' . $idp_id . ', attr_id:' . $attributeid . ', sp_id:' . $spid);
        log_message('debug', $this->mid . 'Checking if arp exists already');

        $arp = $this->tmp_arps->getOneSPPolicy($idp_id, $attributeid, $spid);
        if (!empty($arp)) {
            log_message('debug', $this->mid . 'Arp found in db, proceeding action');
            if ($action == 'delete') {
                $this->em->remove($arp);
                $this->em->flush();
                log_message('debug', $this->mid . 'action: delete - removing arp');
            } elseif ($action == 'modify') {
                $old_policy = $arp->getPolicy();
                $arp->setPolicy($policy);
                $this->em->persist($arp);
                $this->em->flush();
                log_message('debug', $this->mid . 'action: modify - modifying arp from policy ' . $old_policy . ' to ' . $policy);
            } else {
                log_message('error', $this->mid . 'wrong action in post, it should be modify or delete but got ' . $action);
                show_error('Something went wrong');
            }
        } else {
            log_message('debug', $this->mid . 'Arp not found');
            if ($action == 'create') {
                log_message('debug', $this->mid . 'Creating new arp');
                $narp = new models\AttributeReleasePolicy;
                $narp->setSpecificPolicy($idp, $attribute, $spid, $policy);
                $this->em->persist($narp);
                $this->em->flush();
            }
        }



        return $this->globals($idp_id);
    }

    public function submit_multi($idp_id) {

        $submited_provider_id = $this->input->post('idpid');
        if (empty($submited_provider_id) or ($idp_id != $submited_provider_id)) {
            log_message('error', $this->mid . 'conflivt or empty');
            show_error($this->mid . 'Conflict', 503);
        } else {
            log_message('debug', $this->mid . 'idpid passed correctly');
        }
        $submited_policies = $this->input->post('policy');
        $submited_requester_id = $this->input->post('spid');
        $idp = $this->tmp_providers->getOneIdpById($submited_provider_id);
        $sp = $this->tmp_providers->getOneSpById($submited_requester_id);

        if (empty($idp) or empty($sp)) {
            log_message('error', $this->mid . 'IdP with id:' . $submited_provider_id . ' or SP with id:' . $submited_requester_id . ' not found');
            show_error($this->mid . 'Provider not found', 404);
        }
        $resource = $idp->getId();
        $group = 'idp';
        $has_write_access = $this->zacl->check_acl($resource, 'write', $group, '');
        if (!$has_write_access) {
            $data['content_view'] = 'nopermission';
            $data['error'] = "No access to edit idp";
            $this->load->view('page', $data);
            return;
        }

        foreach ($submited_policies as $key => $value) {
            if ($value == '100') {
                $arp = $this->tmp_arps->getOneSPPolicy($idp->getId(), $key, $sp->getId());
                if (!empty($arp)) {
                    $this->em->remove($arp);
                }
            } else {
                $arp = $this->tmp_arps->getOneSPPolicy($idp->getId(), $key, $sp->getId());
                if (!empty($arp)) {
                    $old_policy = $arp->getPolicy();
                    if ($value == 0 or $value == 1 or $value == 2) {
                        $arp->setPolicy($value);
                        $this->em->persist($arp);
                        log_message('debug', $this->mid . 'policy changed for arp_id:' . $arp->getId() . ' from ' . $old_policy . ' to ' . $value . ' ready for sync');
                    } else {
                        log_message('error', $this->mid . 'policy couldnt be changed for arp_id:' . $arp->getId() . ' from ' . $old_policy . ' to ' . $value);
                    }
                } else {
                    if ($value == 0 or $value == 1 or $value == 2) {
                        log_message('debug', $this->mid . 'create new arp record for idp:' . $idp->getEntityId());
                        $new_arp = new models\AttributeReleasePolicy;
                        $attr = $this->tmp_attrs->getAttributeById($key);

                        $new_arp->setAttribute($attr);
                        $new_arp->setProvider($idp);
                        $new_arp->setType('sp');
                        $new_arp->setPolicy($value);
                        $new_arp->setRequester($sp->getId());
                        $this->em->persist($new_arp);
                    }
                }
            }
        }
        $this->em->flush();
        return $this->multi($idp->getId(), 'sp', $sp->getId());
    }

    public function multi($idp_id, $type, $requester) {
        if (!($type == 'sp' or $type == 'fed')) {
            log_message('debug', $this->mid . 'wrong type:' . $type . ' (expected sp or fed)');
            show_error($this->mid . 'wrong url request', 404);
        }
        $tmp_attrs = new models\Attributes;

        $tmp_requirements = new models\AttributeRequirements;
        $idp = $this->tmp_providers->getOneIdPById($idp_id);

        if (empty($idp)) {
            log_message('error', $this->mid . '(manage/attribute_policy/multi) Identity Provider not found with id:' . $idp_id);
            show_error($this->mid . 'Identity Provider not found ', 404);
        }
        $resource = $idp->getId();
        $group = 'idp';
        $has_write_access = $this->zacl->check_acl($resource, 'write', $group, '');
        if (!$has_write_access) {
            $data['content_view'] = 'nopermission';
            $data['error'] = "No access to edit idp";
            $this->load->view('page', $data);
            return;
        }
        $data['provider'] = $idp->getName();
        $data['provider_id'] = $idp->getId();
        if ($type == 'sp') {
            log_message('debug', $this->mid . '(manage/attribute_policy/multi) type SP');
            $data['content_view'] = 'manage/attribute_policy_multi_sp_view';
            $sp = $this->tmp_providers->getOneSpById($requester);

            if (empty($sp)) {
                log_message('error', $this->mid . '(manage/attribute_policy/multi) Service Provider as requester not found with id:' . $requester);
                show_error($this->mid . 'Service Provider not found ', 404);
            }
            $data['requester'] = $sp->getName();
            $data['requester_id'] = $sp->getId();
            $data['requester_type'] = 'SP';
            /**
             * @todo fix it
             */
            $is_available = $sp->getAvailable();
            if (empty($is_available)) {
                log_message('debug', 'Service Provider exists but it\'s not available (disabled, timevalid)');
                /**
                 * @todo finish it
                 */
            }

            $arps = $this->tmp_arps->getSpecificPolicyAttributes($idp, $requester);
            $arps_array = array();
            foreach ($arps as $a) {
                $attr_name = $a->getAttribute()->getName();
                $arps_array[$attr_name] = array(
                    'attr_name' => $attr_name,
                    'supported' => 0,
                    'attr_id' => $a->getAttribute()->getId(),
                    'attr_policy' => $a->getPolicy(),
                    'idp_id' => $a->getProvider()->getId(),
                    'sp_id' => $a->getRequester(),
                    'req_status' => null,
                    'req_reason' => null,
                );
            }
            $supported_attrs = $this->tmp_arps->getSupportedAttributes($idp);
            foreach ($supported_attrs as $p) {
                $attr_name = $p->getAttribute()->getName();
                $arps_array[$attr_name]['supported'] = 1;
                if (!array_key_exists('attr_id', $arps_array[$attr_name])) {
                    $arps_array[$attr_name]['attr_name'] = $attr_name;
                    $arps_array[$attr_name]['attr_id'] = $p->getAttribute()->getId();
                    $arps_array[$attr_name]['attr_policy'] = null;
                    $arps_array[$attr_name]['idp_id'] = $p->getProvider()->getId();
                    $arps_array[$attr_name]['sp_id'] = $requester;
                    $arps_array[$attr_name]['req_status'] = null;
                    $arps_array[$attr_name]['req_reason'] = null;
                }
            }
            $requirements = $tmp_requirements->getRequirementsBySP($sp);
            foreach ($requirements as $r) {
                $attr_name = $r->getAttribute()->getName();
                if (!array_key_exists($attr_name, $arps_array)) {
                    $arps_array[$attr_name] = array(
                        'attr_name' => $attr_name,
                        'supported' => 0,
                        'attr_id' => $r->getAttribute()->getId(),
                        'attr_policy' => null,
                        'idp_id' => null,
                        'sp_id' => $r->getSP()->getId(),
                        'req_status' => null,
                        'req_reason' => null,
                    );
                }

                $arps_array[$attr_name]['attr_name'] = $attr_name;
                $arps_array[$attr_name]['req_status'] = $r->getStatus();
                $arps_array[$attr_name]['req_reason'] = $r->getReason();
            }
            $data['arps'] = $arps_array;
            $data['policy_dropdown'] = $this->config->item('policy_dropdown');
            $data['policy_dropdown']['100'] = 'not set';
            $data['provider'] = $idp->getName();
            $data['provider_id'] = $idp->getId();
            $data['provider_entityid'] = $idp->getEntityId();
            $data['requester'] = $sp->getName();
            $data['requester_id'] = $sp->getId();
            $data['requester_entityid'] = $sp->getEntityId();


            /**
             * @todo finish
             */
        } elseif ($type == 'fed') {
            $data['content_view'] = 'manage/attribute_policy_multi_fed_view';
            log_message('debug', $this->mid . '(manage/attribute_policy/multi) type FED');
            /**
             * @todo finish
             */
        }
        $this->load->view('page', $data);
    }

    public function _specific_validate() {
        
    }

    public function specific($idp_id, $type) {
        if (!is_numeric($idp_id)) {
            show_error('Id of IdP is not numeric', 404);
        }
        $resource = $idp_id;
        $group = 'idp';
        $has_write_access = $this->zacl->check_acl($resource, 'write', $group, '');
        if (!$has_write_access) {
            $data['content_view'] = 'nopermission';
            $data['error'] = "No access to edit idp";
            $this->load->view('page', $data);
            return;
        }
        $this->load->library('form_validation');
        if ($type == 'sp') {
            $this->form_validation->set_rules('service', 'Service ID', 'required');
            $sp_id = $this->input->post('service');
            if ($this->form_validation->run() === FALSE) {
                show_error($this->mid . 'Empty value is not allowed', 404);
            } else {
                redirect(base_url('manage/attribute_policy/multi/' . $idp_id . '/sp/' . $sp_id), 'refresh');
            }
        }
    }

}
