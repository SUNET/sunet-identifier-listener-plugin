<?php
/**
 * COmanage Registry Identifier Copier Bootstrap
 *
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry-plugin
 * @since         COmanage Registry v3.2.0
 * @copyright     
 * @license       
 */

App::uses('CakeEventManager', 'Event');
App::uses('IdentifierCopierListener', 'IdentifierCopier.Lib');
CakeEventManager::instance()->attach(new IdentifierCopierListener());
