<?php
/**
 * COmanage Registry Identifier Copier Listener
 *
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry-plugin
 * @since         COmanage Registry v3.2.0
 * @copyright     
 * @license       
 */

App::uses('CakeEventListener', 'Event');

class IdentifierCopierListener implements CakeEventListener {
  /**
   * Define our listener(s)
   *
   * @since  COmanage Registry v3.2.0
   * @return Array Array of events and associated function names
   */
    
  public function implementedEvents() {
    return array(
      'Model.afterSave'   => 'copyIdentifier'
    );
  }

  /**
   * Handle the actual synchronization of an identifier.
   *
   * @since  COmanage Registry v3.2.0
   * @param  Identifier $Identifier Identifier class instantiation
   * @param  Array      $id         Identifier record
   * 
   */
  
  protected function syncIdentifier($Identifier, $id, $delete = false) {
    // We need the corresponding CO Person ID
    $CoOrgIdentityLink = ClassRegistry::init('CoOrgIdentityLink');
    
    $args = array();
    $args['conditions']['CoOrgIdentityLink.org_identity_id'] = $id['org_identity_id'];
    $args['contain'] = 'CoPerson';
    
    $link = $CoOrgIdentityLink->find('first', $args);
    
    if(!$link || empty($link['CoOrgIdentityLink']['co_person_id'])) {
      // No CO Person ID, nothing to do
      return true;
    }
    
    // For now we hardcode the CO we're interested in, though ultimately it
    // would be better to enable on a per-CO basis, eg
    //  https://bugs.internet2.edu/jira/browse/CO-1646
    if($link['CoPerson']['co_id'] != 2) {
      // If CO is not 2, don't do anything
      return true;
    }

    // Is there already an identifier of type externaleppn already 
    // associated with the CoPerson record?
    $args = array();
    $args['conditions']['Identifier.type'] = 'externaleppn';
    $args['conditions']['Identifier.co_person_id'] = $link['CoOrgIdentityLink']['co_person_id'];
    $args['contain'] = false;

    $curId = $Identifier->find('first', $args);

    if(!empty($curId)) {
      if($curId['Identifier']['identifier'] == $id['identifier']) {
        // Nothing to do, identifier already exists.
        return true;
      }
    }

    $newId = array(
      'Identifier' => array(
        'identifier'           => $id['identifier'],
        'co_person_id'         => $link['CoOrgIdentityLink']['co_person_id'],
        'type'                 => 'externaleppn',
        'status'               => StatusEnum::Active,
        'login'                => false
      )
    );
    
    if(!empty($curId['Identifier']['id'])) {
      // Update the existing record
      $newId['Identifier']['id'] = $curId['Identifier']['id'];
    }
    
    // Make sure the validation rules are set for extended types, which isn't
    // set when we get here via CoOrgIdentityLink (since it does not extend
    // MVPAController).
    
    $vrule = $Identifier->validate['type']['content']['rule'];
    $vrule[1]['coid'] = $link['CoPerson']['co_id'];
    $Identifier->validator()->getField('type')->getRule('content')->rule = $vrule;    
    
    $Identifier->clear();
    // We should really check if we're in an enrollment, and if so pass
    // provision=false, but we don't really have a good way to check.
    $Identifier->save($newId);
    
    return true;
  }
  
  /**
   * Handle an Identifier Saved event by creating or updating the associated
   * identifier.
   *
   * @since  COmanage Registry v3.2.0
   * @param  CakeEvent $event Cake Event
   * @return Boolean True on success
   */
  
  public function copyIdentifier(CakeEvent $event) {
    
    $subject = $event->subject();
    
    if($subject->name == 'Identifier') {
      $identifier = $subject->data['Identifier'];
      
      if(!empty($identifier['org_identity_id'])
        // For now we only fire on identifiers of type ePPN added
        // to the organizational identity.
         && ($identifier['type'] == IdentifierEnum::ePPN)
         && isset($identifier['login']) && $identifier['login']
         && !empty($identifier['identifier'])) {
        $Identifier = ClassRegistry::init('Identifier');
        
        $this->syncIdentifier($Identifier, $identifier);
      }
    } elseif($subject->name == 'CoOrgIdentityLink') {
      // If an Org Identity is linked to a CO Person (which might happen eg
      // after an OIS record is created and pipelined to a CO Person), we need
      // to check for any identifiers to copy.
      
      $link = $subject->data['CoOrgIdentityLink'];
      
      if(!empty($link['org_identity_id'])) {
        // Look for Identifiers attached to the Org Identity.
        // For now we only fire on identifiers of type ePPN.
        
        $args = array();
        $args['conditions']['Identifier.org_identity_id'] = $link['org_identity_id'];
        $args['conditions']['Identifier.type'] = IdentifierEnum::ePPN;
        $args['conditions']['Identifier.login'] = true;
        $args['contain'] = false;
        
        $Identifier = ClassRegistry::init('Identifier');
        
        $ids = $Identifier->find('all', $args);
        
        foreach($ids as $id) {
          $this->syncIdentifier($Identifier, $id['Identifier']);
        }
      }
    }
    
    // Return true to keep the event flowing
    return true;
  }
}
