<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');
/**
 * Jagger
 * 
 * @package     Jagger
 * @author      Middleware Team HEAnet 
 * @copyright   Copyright (c) 2014, HEAnet Limited (http://www.heanet.ie)
 * @license     MIT http://www.opensource.org/licenses/mit-license.php
 *  
 */

/**
 * Metadata2import Class
 * 
 * @package     Jagger
 * @subpackage  Libraries
 * @author      Janusz Ulanowski <janusz.ulanowski@heanet.ie>
 */
class Metadata2import {

    private $metadata_in_array;
    private $metadata;
    private $type;
    private $full;
    private $defaults;
    private $other;
    protected $ci;
    protected $em;

    function __construct()
    {
        $this->ci = &get_instance();
        $this->em = $this->ci->doctrine->em;
        $this->ci->load->library('metadata2array');
        $this->metadata = null;
        $this->type = null;
        $this->full = false;

        $this->defaults = array(
            'static' => true,
            'local' => false,
            'federation' => null,
            'live' => false,
            'removeexternal' => false,
            'mailreport' => false,
        );
        $this->other = null;
    }

    private function _report($report)
    {
            if(!(!empty($report) && is_array($report)))
            {
               return false;
            }
            $this->ci->load->library('email_sender');
            $body = 'Report'.PHP_EOL;

            foreach($report['body']  as $bb)
            {
                $body .= $bb.PHP_EOL;
            }


            $structureChanged = FALSE;
            if(count($report['provider']['new'])> 0)
            {
                $structureChanged = TRUE;
                $body .='List new providers registered during sync:'.PHP_EOL;
                foreach($report['provider']['new'] as $a)
                {
                    $body .= $a.PHP_EOL;
                }
            }
            if(count($report['provider']['joinfed'])> 0)
            {
                $structureChanged = TRUE;
                $body .='List existing providers added to federation during sync:'.PHP_EOL;
                foreach($report['provider']['joinfed'] as $a)
                {
                    $body .= $a.PHP_EOL;
                }
            }
            if(count($report['provider']['del'])> 0)
            {
                $structureChanged = TRUE;
                $body .='List providers removed from the system during sync:'.PHP_EOL;
                foreach($report['provider']['del'] as $a)
                {
                    $body .= $a.PHP_EOL;
                }
            }
            if(count($report['provider']['leavefed'])> 0)
            {
                $structureChanged = TRUE;
                $body .='List providers removed from federation during sync:'.PHP_EOL;
                foreach($report['provider']['leavefed'] as $a)
                {
                    $body .= $a.PHP_EOL;
                }
            }
            $nbody = '';
            if(!$structureChanged)
            {
                $nbody ='No entities have been added/removed after sync/import'.PHP_EOL;
            }
            else
            {
                $this->ci->email_sender->addToMailQueue(array('gfedmemberschanged'),null,'Federation sync/import report',$body,array(),false);
            }

    }
    public function import($metadata, $type, $full, array $defaults, $other = null)
    {
        $tmpProviders = new models\Providers;
        $this->metadata = &$metadata;

        $this->full = $full;
        $this->type = $type;
        $this->other = $other;
        $this->defaults = array_merge($this->defaults, $defaults);
        if (empty($this->full) && empty($this->defaults['static']))
        {
            return false;
        }
        $coclist = $this->em->getRepository("models\Coc")->findAll();
        $attrsDefinitions = $this->em->getRepository("models\Attribute")->findAll();

        $attributes = array();
        $report = array(
            'subject' => '',
            'body' => array(),
            'provider' => array(
                'new' => array(),
                'del' => array(),
                'joinfed' => array(),
                'leavefed' => array(),
            ),
        );
        foreach ($attrsDefinitions as $v)
        {
            $attributes['' . $v->getOid() . ''] = $v;
        }
        $coclistconverted = array();
        $coclistarray = array();

        foreach ($coclist as $k => $c)
        {
            $coclistconverted[$c->getId()] = $c;
            $coclistarray['' . $c->getId() . ''] = $c->getUrl();
        }



        if (array_key_exists('federations', $this->defaults))
        {
            $federations = $this->em->getRepository("models\Federation")->findBy(array('name' => $this->defaults['federations']));
            foreach ($federations as $ff)
            {
                $report['body'][] = 'Sync with federation: ' . $ff->getName();
            }
        }
        /**
         * if param static is not provided then static is set to true 
         */
        if (array_key_exists('static', $this->defaults) && $this->defaults['static'] === FALSE)
        {
            $static = false;
        } else
        {
            $static = true;
        }
        if (array_key_exists('local', $this->defaults) && $this->defaults['local'] === true)
        {
            $local = true;
        } else
        {
            $local = false;
        }
        if (array_key_exists('active', $this->defaults) && $this->defaults['active'] === true)
        {
            $active = true;
        } else
        {
            $active = false;
        }
        if (array_key_exists('overwritelocal', $this->defaults) && $this->defaults['overwritelocal'] === TRUE)
        {
            $overwritelocal = true;
        } else
        {
            $overwritelocal = false;
        }

        // remove external entities if they're not member of any other federation
        if (array_key_exists('removeexternal', $this->defaults) && $this->defaults['removeexternal'] === TRUE)
        {
            $removeexternal = true;
        } else
        {
            $removeexternal = false;
        }
        /**
         * @todo replace it
         */
        /**
         * begin block
         */
        $mailReport = FALSE;
        $mailAddresses = array();
        if (array_key_exists('mailreport', $this->defaults) && $this->defaults['mailreport'] === TRUE)
        {
            $mailReport = TRUE;
            if (array_key_exists('email', $this->defaults) && !empty($this->defaults['email']))
            {
                $mailAddresses[] = $this->defaults['email'];
            } else
            {
                $a = $this->em->getRepository("models\AclRole")->findOneBy(array('name' => 'Administrator'));
                $a_members = $a->getMembers();
                foreach ($a_members as $m)
                {
                    $mailAddresses[] = $m->getEmail();
                }
            }
        }
        /**
         * end block
         */
        $this->metadata_in_array = $this->ci->metadata2array->rootConvert($metadata, $full);

        if (!(empty($this->metadata_in_array) || is_array($this->metadata_in_array) || count($this->metadata_in_array) == 0))
        {
            \log_message('warning', __METHOD__ . ' converting xml metadata 
                               into array resulted empty array or null value');
            return null;
        }



        foreach ($federations as $f)
        {
            $membershipColl = $f->getMembership();
            $membership = $membershipColl->toArray();
            $membershipByEnt = array();
            foreach ($membership as $k => $m)
            {
                $membershipByEnt['' . $m->getProvider()->getEntityId() . ''] = array('mshipKey' => $k, 'mship' => &$m);
            }

            if (empty($this->defaults['localimport']))
            {
                \log_message('info', __METHOD__ . ' running as sync for ' . $f->getName());
                foreach ($membershipColl as $m)
                {
                    $membershipByEnt['' . $m->getProvider()->getEntityId() . ''] = $m;
                }
                // list entities in the source 
                $membersFromSync = array();
                foreach ($this->metadata_in_array as $ent)
                {

                    // START if type matches 
                    if ($ent['type'] === 'BOTH' ||
                            $ent['type'] === $type ||
                            $type == 'ALL')
                    {

                        $importedProvider = new models\Provider;
                        $importedProvider->setProviderFromArray($ent);
                        $existingProvider = $tmpProviders->getOneByEntityId($importedProvider->getEntityId());
                        if (empty($existingProvider))
                        {
                            
                            $membersFromSync[] = $importedProvider->getEntityId();
                            $importedProvider->setStatic($static);
                            $importedProvider->setLocal($local);
                            $importedProvider->setActive($active);
                            // coc begin
                            if (array_key_exists('coc', $ent) && !empty($ent['coc']))
                            {
                                $y = array_search($ent['coc'], $coclistarray);
                                if ($y != NULL OR $y != FALSE)
                                {
                                    $celement = $coclistconverted['' . $y . ''];
                                    if (!empty($celement))
                                    {
                                        $importedProvider->setCoc($celement);
                                    }
                                }
                            } else
                            {
                                $importedProvider->setCoc(NULL);
                            }
                            // attr req  start
                            if (isset($ent['details']['reqattrs']))
                            {
                                $attrsset = array();
                                foreach ($ent['details']['reqattrs'] as $r)
                                {
                                    if (array_key_exists($r['name'], $attributes))
                                    {
                                        if (!in_array($r['name'], $attrsset))
                                        {
                                            $reqattr = new models\AttributeRequirement;
                                            $reqattr->setAttribute($attributes['' . $r['name'] . '']);
                                            $reqattr->setType('SP');
                                            $reqattr->setSP($importedProvider);
                                            if (isset($r['req']) && strcasecmp($r['req'], 'true') == 0)
                                            {
                                                $reqattr->setStatus('required');
                                            } else
                                            {
                                                $reqattr->setStatus('desired');
                                            }
                                            $reqattr->setReason('');
                                            $importedProvider->setAttributesRequirement($reqattr);
                                            $this->em->persist($reqattr);
                                            $attrsset[] = $r['name'];
                                        }
                                    } else
                                    {
                                        log_message('warning', 'Attr couldnt be set as required becuase doesnt exist in attrs table: ' . $r['name']);
                                    }
                                }
                            }

                            // attr req end
                            $newmembership = new models\Federationmembers();
                            $newmembership->setProvider($importedProvider);
                            $newmembership->setFederation($f);
                            $newmembership->setJoinState('3');
                            $report['provider']['new'][] = $importedProvider->getEntityId();
                            $this->em->persist($newmembership);
                            $this->em->persist($importedProvider);
                        } // END for new provider
                        else
                        { // provider exist
                            $membersFromSync[] = $existingProvider->getEntityId();
                            $isLocal = $existingProvider->getLocal();
                            $isLocked = $existingProvider->getLocked();
                            $updateAllowed = (($isLocal && $overwritelocal && !$isLocked) OR !$isLocal);
                            if ($updateAllowed)
                            {
                                $existingProvider->overwriteByProvider($importedProvider);
                                if (array_key_exists('coc', $ent) && empty($ent['coc']))
                                {
                                    $y = array_search($ent['coc'], $coclistarray);
                                    if ($y != NULL OR $y != FALSE)
                                    {
                                        $celement = $coclistconverted['' . $y . ''];
                                        if (!empty($celement))
                                        {
                                            $existingProvider->setCoc($celement);
                                        }
                                    }
                                } else
                                {
                                    $existingProvider->setCoc(NULL);
                                }
                                $existingProvider->setStatic($static);
                                $duplicateControl = array();
                                $requiredAttrs = $existingProvider->getAttributesRequirement();
                                foreach ($requiredAttrs as $a)
                                {
                                    $oid = $a->getAttribute()->getOid();
                                    if (in_array('' . $oid . '', $duplicateControl))
                                    {
                                        $requiredAttrs->removeElement($a);
                                        $this->em->remove($a);
                                    } else
                                    {
                                        $duplicateControl[] = $oid;
                                    }
                                }
                                if (isset($ent['details']['reqattrs']) && is_array($ent['details']['reqattrs']))
                                {
                                    foreach ($requiredAttrs as $r)
                                    {
                                        $found = false;
                                        $roid = $r->getAttribute()->getOid();
                                        foreach ($ent['details']['reqattrs'] as $k => $v)
                                        {
                                            if (strcmp($roid, $v['name']) == 0)
                                            {
                                                $found = true;
                                                if (isset($v['req']) && strcasecmp($v['req'], 'true') == 0)
                                                {
                                                    $r->setStatus('required');
                                                } else
                                                {
                                                    $r->setStatus('desired');
                                                }
                                                unset($ent['details']['reqattrs']['' . $k . '']);
                                                $this->em->persist($r);
                                            }
                                            if ($found)
                                            {
                                                break;
                                            }
                                        }
                                        if (!$found)
                                        {
                                            $requiredAttrs->removeElement($r);
                                            $this->em->remove($r);
                                        }
                                    }
                                    foreach ($ent['details']['reqattrs'] as $nr)
                                    {
                                        if (isset($nr['name']) && array_key_exists($nr['name'], $attributes))
                                        {
                                            $reqattr = new models\AttributeRequirement;
                                            $reqattr->setAttribute($attributes['' . $nr['name'] . '']);
                                            $reqattr->setType('SP');
                                            $reqattr->setSP($existingProvider);
                                            if (isset($nr['req']) && strcasecmp($nr['req'], 'true') == 0)
                                            {
                                                $reqattr->setStatus('required');
                                            } else
                                            {
                                                $reqattr->setStatus('desired');
                                            }
                                            $reqattr->setReason('');
                                            $existingProvider->setAttributesRequirement($reqattr);
                                            $this->em->persist($reqattr);
                                        } else
                                        {
                                            log_message('warning', 'Attr couldnt be set as required becuase doesnt exist in attrs table: ' . $nr['name']);
                                        }
                                    }
                                }
                                /**
                                 * END attrs requirements processing
                                 */
                            }



                            if (!array_key_exists($existingProvider->getEntityId(), $membershipByEnt))
                            {
                                if (($isLocal && !$isLocked) || !($isLocal))
                                {
                                    
                                    $newMembership = new models\FederationMembers;
                                    $newMembership->setProvider($existingProvider);
                                    $newMembership->setFederation($f);
                                    $newMembership->setJoinState('3');
                                    $this->em->persist($newMembership);
                                    $report['provider']['joinfed'][] = $existingProvider->getEntityId();
                                }
                            }
                            $this->em->persist($existingProvider);
                        }
                    } // END if type matches
                }
                 
                $currentMembershipList = array_keys($membershipByEnt);
            
                $membersdiff = array_diff($currentMembershipList,$membersFromSync);
                if(count($membersdiff)> 0)
                {
                    log_message('debug',__METHOD__.' found diff in membership, not existing members in external metadata '.serialize($membersdiff));
                    foreach($membersdiff as $d)
                    {
                       $mm2 = $membershipByEnt[''.$d.''];
                       log_message('debug', __METHOD__.' proceeding removing '.$mm2->getProvider()->getEntityId() . ' from fed:'.$f->getName());
                       $mm2joinstate = $mm2->getJoinState();
                       $tmpprov = $mm2->getProvider();
                       
                       $isLocal = $mm2->getProvider()->getLocal();
                       if(!($mm2joinstate == 0 ||  $mm2joinstate == 1))
                       {
                          
                          log_message('debug','proceeding '.$mm2->getProvider()->getEntityId() . ' joinstatus:'.$mm2joinstate);
                          if(!$isLocal && $removeexternal)
                          {
                              $ff = $tmpprov->getFederations();  
                              $countFeds = $ff->count();
                              if($countFeds < 2 && $ff->contains($f) )
                              {
                                $report['provider']['del'][] = $tmpprov->getEntityId();
                                $this->em->remove($tmpprov);
                              }
                              else
                              {
                                 $report['provider']['leavefed'][] = $tmpprov->getEntityId();
                                 $this->em->remove($mm2);
                              }

                          }
                          elseif($mm2joinstate != 2)
                          {
                              $this->em->remove($mm2);
                          } 
                       } 
                       elseif($mm2joinstate == 0 && !$isLocal)
                       {
                            if($removeexternal)
                            {
                                $countFeds = $mm2->getProvider()->getFederations()->count();
                                if($countFeds < 2)
                                {
                                    $this->em->remove($mm2->getProvider());
                                }
                            }
                            else
                            {
                               $this->em->remove($mm2);
                            }

                       }

                    }
                }
               try
               {
                 $this->_report($report);
                 $this->em->flush();
               }
               catch(Exception $e)
               {
                  log_message('error',__METHOD__.' ' .$e);
                  return false;
               }
                 
            }  // END SYNC
            else
            {
                \log_message('info', __METHOD__ . ' running as import for ' . $f->getName() . '
                  - new entities will be created and added to federation(s)');

                foreach ($this->metadata_in_array as $ent)
                {
                    if ($ent['type'] === 'BOTH' ||
                            $ent['type'] === $type ||
                            $type == 'ALL')
                    {
                        $importedProvider = new models\Provider;
                        $importedProvider->setProviderFromArray($ent);

                        $existingProvider = $tmpProviders->getOneByEntityId($importedProvider->getEntityId());
                        if (empty($existingProvider))
                        {
                            $importResult[] = lang('provcreated').': '.$importedProvider->getEntityId(); 
                            $importedProvider->setStatic($static);
                            $importedProvider->setLocal($local);
                            $importedProvider->setActive($active);
                            // coc begin
                            if (array_key_exists('coc', $ent) && !empty($ent['coc']))
                            {
                                $y = array_search($ent['coc'], $coclistarray);
                                if ($y != NULL OR $y != FALSE)
                                {
                                    $celement = $coclistconverted['' . $y . ''];
                                    if (!empty($celement))
                                    {
                                        $importedProvider->setCoc($celement);
                                    }
                                }
                            } else
                            {
                                $importedProvider->setCoc(NULL);
                            }
                            // coc end
                            // attr req  start
                            if (isset($ent['details']['reqattrs']))
                            {
                                $attrsset = array();
                                foreach ($ent['details']['reqattrs'] as $r)
                                {
                                    if (array_key_exists($r['name'], $attributes))
                                    {
                                        if (!in_array($r['name'], $attrsset))
                                        {
                                            $reqattr = new models\AttributeRequirement;
                                            $reqattr->setAttribute($attributes['' . $r['name'] . '']);
                                            $reqattr->setType('SP');
                                            $reqattr->setSP($importedProvider);
                                            if (isset($r['req']) && strcasecmp($r['req'], 'true') == 0)
                                            {
                                                $reqattr->setStatus('required');
                                            } else
                                            {
                                                $reqattr->setStatus('desired');
                                            }
                                            $reqattr->setReason('');
                                            $importedProvider->setAttributesRequirement($reqattr);
                                            $this->em->persist($reqattr);
                                            $attrsset[] = $r['name'];
                                        }
                                    } else
                                    {
                                        log_message('warning', 'Attr couldnt be set as required becuase doesnt exist in attrs table: ' . $r['name']);
                                    }
                                }
                            }

                            // attr req end
                            // set membership
                            $isLocal = $importedProvider->getLocal();
                            $newmembership = new models\Federationmembers();
                            $newmembership->setProvider($importedProvider);
                            $newmembership->setFederation($f);
                            if ($isLocal)
                            {
                                $newmembership->setJoinState('1');
                            }
                            //set membership end

                            $this->em->persist($newmembership);
                            $this->em->persist($importedProvider);
                        } else
                        { // for existing entity
                            $importEntity = '';
                            $elocal = $existingProvider->getLocal();
                            $isLocked = $existingProvider->getLocked();
                            $updateAllowed = (($elocal && $overwritelocal && !$isLocked) OR !$elocal);
                            if ($updateAllowed)
                            {
                                $importEntity .=  lang('provupdated');
                                $existingProvider->overwriteByProvider($importedProvider);
                                $existingProvider->setLocal($this->defaults['local']);
                                if (array_key_exists('coc', $ent) && empty($ent['coc']))
                                {
                                    $y = array_search($ent['coc'], $coclistarray);
                                    if ($y != NULL OR $y != FALSE)
                                    {
                                        $celement = $coclistconverted['' . $y . ''];
                                        if (!empty($celement))
                                        {
                                            $existingProvider->setCoc($celement);
                                        }
                                    }
                                } else
                                {
                                    $existingProvider->setCoc(NULL);
                                }

                                $existingProvider->setStatic($static);
                                /**
                                 *   attrs requirements processing
                                 */
                                $duplicateControl = array();
                                $requiredAttrs = $existingProvider->getAttributesRequirement();
                                foreach ($requiredAttrs as $a)
                                {
                                    $oid = $a->getAttribute()->getOid();
                                    if (in_array('' . $oid . '', $duplicateControl))
                                    {
                                        $requiredAttrs->removeElement($a);
                                        $this->em->remove($a);
                                    } else
                                    {
                                        $duplicateControl[] = $oid;
                                    }
                                }
                                if (isset($ent['details']['reqattrs']) && is_array($ent['details']['reqattrs']))
                                {
                                    foreach ($requiredAttrs as $r)
                                    {
                                        $found = false;
                                        $roid = $r->getAttribute()->getOid();
                                        foreach ($ent['details']['reqattrs'] as $k => $v)
                                        {
                                            if (strcmp($roid, $v['name']) == 0)
                                            {
                                                $found = true;
                                                if (isset($v['req']) && strcasecmp($v['req'], 'true') == 0)
                                                {
                                                    $r->setStatus('required');
                                                } else
                                                {
                                                    $r->setStatus('desired');
                                                }
                                                unset($ent['details']['reqattrs']['' . $k . '']);
                                                $this->em->persist($r);
                                            }
                                            if ($found)
                                            {
                                                break;
                                            }
                                        }
                                        if (!$found)
                                        {
                                            $requiredAttrs->removeElement($r);
                                            $this->em->remove($r);
                                        }
                                    }
                                    foreach ($ent['details']['reqattrs'] as $nr)
                                    {
                                        if (isset($nr['name']) && array_key_exists($nr['name'], $attributes))
                                        {
                                            $reqattr = new models\AttributeRequirement;
                                            $reqattr->setAttribute($attributes['' . $nr['name'] . '']);
                                            $reqattr->setType('SP');
                                            $reqattr->setSP($existingProvider);
                                            if (isset($nr['req']) && strcasecmp($nr['req'], 'true') == 0)
                                            {
                                                $reqattr->setStatus('required');
                                            } else
                                            {
                                                $reqattr->setStatus('desired');
                                            }
                                            $reqattr->setReason('');
                                            $existingProvider->setAttributesRequirement($reqattr);
                                            $this->em->persist($reqattr);
                                        } else
                                        {
                                            log_message('warning', 'Attr couldnt be set as required becuase doesnt exist in attrs table: ' . $nr['name']);
                                        }
                                    }
                                }
                                /**
                                 * END attrs requirements processing
                                 */
                            }
                            if (!($isLocked && $elocal))
                            {
                                $settingMebership = $this->em->getRepository("models\FederationMembers")->findOneBy(array('provider' => $existingProvider, 'federation' => $f->getId()));
                                if (empty($settingMebership))
                                {
                                    $newMembership = new models\FederationMembers();
                                    $newMembership->setProvider($existingProvider);
                                    $newMembership->setFederation($f);
                                    $newMembership->setJoinState('1');
                                    $this->em->persist($newMembership);
                                    $importEntity .= ', '.lang('rr_addedtofed'); 
                                } else
                                {
                                    $cjoinstate = $settingMebership->getJoinState();
                                    if($cjoinstate == 2)
                                    {
                                       $importEntity .= '; '.lang('rr_addedtofed');
                                    }
                                    elseif($cjoinstate == 3)
                                    {
                                        $importEntity .= '; '.lang('rr_convertjoinstate31');
                                    }
                                    else
                                    {
                                        $importEntity .= '; '.lang('rr_joinstatealreadyinfed');
                                    }
                                    $settingMebership->setJoinState('1');
                                    $this->em->persist($settingMebership);
                                }
                            }
                            $importResult[] = $importEntity .': '.$existingProvider->getEntityId();
                        } // end for existing provider
                    }
                }
            } // END import 
        }
        try
        {
            $this->em->flush();
            if(!empty($importResult))
            {
                $this->ci->globalnotices['metadataimportmessage']= $importResult;
            }
            return true;
        } catch (Exception $e)
        {
            \log_message('error', __METHOD__ . ' ' . $e);
            return false;
        }
    }

}
