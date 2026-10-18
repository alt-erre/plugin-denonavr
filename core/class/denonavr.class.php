<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

/**
 * Classe principale du plugin Denon AVR pour Jeedom.
 *
 * @author Loïc (auteur original)
 * @author Aurélien Leovant (alt-erre) — Support complet de l'API HEOS (port 8080 / AppCommand.xml), multi-zones et sources dynamiques
 */
class denonavr extends eqLogic {
	/*     * *************************Attributs****************************** */
	
	/**
	 * Limites du volume en dB utilisées par le curseur Jeedom (échelle relative Denon : -80 dB à +18 dB).
	 * En mode HEOS, cette valeur est convertie sur l'échelle absolue 00 à 98 (où 80 = 0 dB).
	 */
	const MAX_VOLUME = 19;
	const MIN_VOLUME = -79;
	
	/*     * ***********************Methode static*************************** */
	
	/**
	 * Tâche cron exécutée toutes les 15 minutes par Jeedom pour rafraîchir l'état
	 * de tous les amplificateurs Denon activés.
	 */
	public static function cron15() {
		foreach (eqLogic::byType('denonavr', true) as $eqLogic) {
			$eqLogic->updateInfo();
		}
	}
	
	/*     * *********************Méthodes d'instance************************* */
	
	/**
	 * Catégorise automatiquement l'équipement dans "Multimédia" lors de sa création.
	 */
	public function preInsert() {
		$this->setCategory('multimedia', 1);
	}
	
	/**
	 * Vérifie la présence obligatoire de l'adresse IP avant la sauvegarde.
	 */
	public function preUpdate() {
		if ($this->getConfiguration('ip') == '') {
			throw new Exception(__('Le champs IP ne peut etre vide', __FILE__));
		}
	}
	
	/**
	 * Crée ou met à jour les commandes Jeedom (infos, actions de base et entrées sources)
	 * après chaque sauvegarde de l'équipement.
	 */
	public function postSave() {
		// Commande info : État d'alimentation (0 = éteint/veille, 1 = allumé)
		$cmd = $this->getCmd(null, 'power_state');
		if (!is_object($cmd)) {
			$cmd = new denonavrCmd();
			$cmd->setLogicalId('power_state');
			$cmd->setIsVisible(0);
			$cmd->setName(__('Etat', __FILE__));
		}
		$cmd->setType('info');
		$cmd->setSubType('binary');
		$cmd->setEqLogic_id($this->getId());
		$cmd->setDisplay('generic_type', 'ENERGY_STATE');
		$cmd->save();
		$power_state_id = $cmd->getId();
		
		// Commande info : Source / Entrée active (ex: AUX1, MPLAY, BD, TV...)
		$cmd = $this->getCmd(null, 'input');
		if (!is_object($cmd)) {
			$cmd = new denonavrCmd();
			$cmd->setLogicalId('input');
			$cmd->setIsVisible(1);
			$cmd->setName(__('Entrée', __FILE__));
		}
		$cmd->setType('info');
		$cmd->setSubType('string');
		$cmd->setEqLogic_id($this->getId());
		$cmd->setDisplay('generic_type', 'GENERIC');
		$cmd->save();
		
		// Commande info : Niveau de volume courant (en dB relatifs, de -79 à +19)
		$cmd = $this->getCmd(null, 'volume');
		if (!is_object($cmd)) {
			$cmd = new denonavrCmd();
			$cmd->setLogicalId('volume');
			$cmd->setIsVisible(0);
			$cmd->setName(__('Volume', __FILE__));
		}
		$cmd->setType('info');
		$cmd->setSubType('numeric');
		$cmd->setEqLogic_id($this->getId());
		$cmd->setUnite('%');
		$cmd->setDisplay('generic_type', 'LIGHT_STATE');
		$cmd->save();
		$volume_id = $cmd->getId();
		
		// Commande info : Mode audio / surround actif (ex: Dolby Surround, Stereo...)
		$cmd = $this->getCmd(null, 'sound_mode');
		if (!is_object($cmd)) {
			$cmd = new denonavrCmd();
			$cmd->setLogicalId('sound_mode');
			$cmd->setIsVisible(1);
			$cmd->setName(__('Audio', __FILE__));
		}
		$cmd->setType('info');
		$cmd->setSubType('string');
		$cmd->setEqLogic_id($this->getId());
		$cmd->setDisplay('generic_type', 'GENERIC');
		$cmd->save();
		
		// Commande action (curseur) : Réglage du volume, liée à la commande info 'volume'
		$cmd = $this->getCmd(null, 'volume_set');
		if (!is_object($cmd)) {
			$cmd = new denonavrCmd();
			$cmd->setLogicalId('volume_set');
			$cmd->setName(__('Volume niveau', __FILE__));
			$cmd->setIsVisible(1);
		}
		$cmd->setType('action');
		$cmd->setSubType('slider');
		$cmd->setConfiguration('minValue', self::MIN_VOLUME);
		$cmd->setConfiguration('maxValue', self::MAX_VOLUME);
		$cmd->setValue($volume_id);
		$cmd->setEqLogic_id($this->getId());
		$cmd->save();
		
		// Commande action : Allumer la zone
		$cmd = $this->getCmd(null, 'on');
		if (!is_object($cmd)) {
			$cmd = new denonavrCmd();
			$cmd->setLogicalId('on');
			$cmd->setName(__('On', __FILE__));
			$cmd->setIsVisible(1);
			$cmd->setTemplate('dashboard', 'prise');
			$cmd->setTemplate('mobile', 'prise');
		}
		$cmd->setType('action');
		$cmd->setSubType('other');
		$cmd->setEqLogic_id($this->getId());
		$cmd->setDisplay('generic_type', 'ENERGY_ON');
		$cmd->setValue($power_state_id);
		$cmd->save();
		
		// Commande action : Éteindre la zone (mise en veille)
		$cmd = $this->getCmd(null, 'off');
		if (!is_object($cmd)) {
			$cmd = new denonavrCmd();
			$cmd->setLogicalId('off');
			$cmd->setName(__('Off', __FILE__));
			$cmd->setIsVisible(1);
			$cmd->setTemplate('dashboard', 'prise');
			$cmd->setTemplate('mobile', 'prise');
		}
		$cmd->setType('action');
		$cmd->setSubType('other');
		$cmd->setEqLogic_id($this->getId());
		$cmd->setDisplay('generic_type', 'ENERGY_OFF');
		$cmd->setValue($power_state_id);
		$cmd->save();
		
		// Commande action : Rafraîchir manuellement les informations de l'ampli
		$cmd = $this->getCmd(null, 'refresh');
		if (!is_object($cmd)) {
			$cmd = new denonavrCmd();
			$cmd->setLogicalId('refresh');
			$cmd->setName(__('Rafraîchir', __FILE__));
			$cmd->setIsVisible(1);
		}
		$cmd->setType('action');
		$cmd->setSubType('other');
		$cmd->setEqLogic_id($this->getId());
		$cmd->save();
		
		// Commande info : État de la sourdine (0 = son actif, 1 = muet)
		$cmd = $this->getCmd(null, 'mute_state');
		if (!is_object($cmd)) {
			$cmd = new denonavrCmd();
			$cmd->setLogicalId('mute_state');
			$cmd->setIsVisible(0);
			$cmd->setName(__('Etat Muet', __FILE__));
		}
		$cmd->setType('info');
		$cmd->setSubType('binary');
		$cmd->setEqLogic_id($this->getId());
		$cmd->save();
		$mute_state_id = $cmd->getId();

		// Commande action : Basculer la sourdine (Mute On / Off)
		$cmd = $this->getCmd(null, 'mute');
		if (!is_object($cmd)) {
			$cmd = new denonavrCmd();
			$cmd->setLogicalId('mute');
			$cmd->setName(__('Muet', __FILE__));
			$cmd->setIsVisible(1);
		}
		$cmd->setType('action');
		$cmd->setSubType('other');
		$cmd->setValue($mute_state_id);
		$cmd->setEqLogic_id($this->getId());
		$cmd->save();
		
		// Table de correspondance des identifiants de modèles historiques (API Standard port 80)
		$convert = array('3' => '2', '8' => '2', '9' => '2', '6' => '5', '11' => '5', '12' => '5', '13' => '5');
		
		// Listes d'entrées par défaut selon le modèle (1..10 = anciens modèles, 'H' = gamme HEOS par défaut)
		$inputModel = array(
			'1' => array(
				'SAT/CBL' => 'CBL/SAT',
				'DVD' => 'DVD/Blu-ray',
				'BD' => 'Blu-ray',
				'GAME' => 'Game',
				'AUX1' => 'AUX',
				'MPLAY' => 'Media Player',
				'USB/IPOD' => 'iPod/USB',
				'TV' => 'TV Audio',
				'TUNER' => 'Tuner',
				'NETHOME' => 'Online Music',
				'BT' => 'Bluetooth',
				'IRP' => 'Internet Radio',
			),
			'7' => array(
				'SAT/CBL' => 'CBL/SAT',
				'DVD' => 'DVD/Blu-ray',
				'GAME' => 'Game',
				'AUX1' => 'AUX',
				'MPLAY' => 'Media Player',
				'USB/IPOD' => 'iPod/USB',
				'TV' => 'TV Audio',
				'TUNER' => 'Tuner',
				'NETHOME' => 'Online Music',
				'BT' => 'Bluetooth',
				'IRP' => 'Internet Radio',
				'CD' => 'CD',
			),
			'2' => array(
				'SAT/CBL' => 'CBL/SAT',
				'DVD' => 'DVD/Blu-ray',
				'BD' => 'Blu-ray',
				'GAME' => 'Game',
				'AUX1' => 'AUX1',
				'AUX2' => 'AUX2',
				'MPLAY' => 'Media Player',
				'USB/IPOD' => 'iPod/USB',
				'TV' => 'TV Audio',
				'TUNER' => 'Tuner',
				'NETHOME' => 'Online Music',
				'BT' => 'Bluetooth',
				'IRP' => 'Internet Radio',
				'CD' => 'CD',
				'SERVER' => 'Media Server',
			),
			'10' => array(
				'SAT/CBL' => 'CBL/SAT',
				'DVD' => 'DVD/Blu-ray',
				'BD' => 'Blu-ray',
				'GAME' => 'Game',
				'AUX1' => 'AUX1',
				'AUX2' => 'AUX2',
				'MPLAY' => 'Media Player',
				'USB/IPOD' => 'iPod/USB',
				'TV' => 'TV Audio',
				'TUNER' => 'Tuner',
				'NETHOME' => 'Online Music',
				'BT' => 'Bluetooth',
				'IRP' => 'Internet Radio',
				'CD' => 'CD',
				'PHONO' => 'Phono',
			),
			'4' => array(
				'SAT/CBL' => 'CBL/SAT',
				'DVD' => 'DVD/Blu-ray',
				'BD' => 'Blu-ray',
				'GAME' => 'Game',
				'AUX1' => 'AUX1',
				'AUX2' => 'AUX2',
				'MPLAY' => 'Media Player',
				'USB/IPOD' => 'iPod/USB',
				'TV' => 'TV Audio',
				'TUNER' => 'Tuner',
				'NETHOME' => 'Online Music',
				'BT' => 'Bluetooth',
				'IRP' => 'Internet Radio',
				'CD' => 'CD',
				'PHONO' => 'Phono',
			),
			'5' => array(
				'SAT/CBL' => 'CBL/SAT',
				'DVD' => 'DVD/Blu-ray',
				'BD' => 'Blu-ray',
				'GAME' => 'Game',
				'AUX1' => 'AUX1',
				'AUX2' => 'AUX2',
				'MPLAY' => 'Media Player',
				'USB/IPOD' => 'iPod/USB',
				'TV' => 'TV Audio',
				'NETHOME' => 'Online Music',
				'BT' => 'Bluetooth',
				'IRP' => 'Internet Radio',
				'CD' => 'CD',
				'PHONO' => 'Phono',
			),
			'H' => array(
				'SAT/CBL' => 'CBL/SAT',
				'DVD' => 'DVD',
				'BD' => 'Blu-ray',
				'GAME' => 'Game',
				'AUX1' => 'AUX1',
				'AUX2' => 'AUX2',
				'MPLAY' => 'Media Player',
				'USB/IPOD' => 'iPod/USB',
				'TV' => 'TV Audio',
				'TUNER' => 'Tuner',
				'NET' => 'HEOS Music',
				'BT' => 'Bluetooth',
				'CD' => 'CD',
				'PHONO' => 'Phono',
			),
		);
		
		// Récupération de la liste des entrées de l'ampli et création des commandes correspondantes
		if ($this->getConfiguration('ip') != '') {
			try {
				$infos = $this->getAmpInfo(true);
			} catch (Exception $e) {
				$infos = array();
			}
			$sources = array();
			if (isset($infos['Sources']) && is_array($infos['Sources']) && count($infos['Sources']) > 0) {
				// Sources actives et renommées remontées dynamiquement par l'ampli (via AppCommand.xml)
				$sources = $infos['Sources'];
			} else if ($this->getConfiguration('mode') == 'H') {
				// Repli sur la liste standard HEOS si l'ampli n'était pas joignable lors de la sauvegarde
				$sources = $inputModel['H'];
			} else if (isset($infos['ModelId'])) {
				// Anciens modèles (API Standard) identifiés par ModelId
				$model = $infos['ModelId'];
				if (isset($convert[$model])) {
					$model = $convert[$model];
				}
				if (isset($inputModel[$model])) {
					$sources = $inputModel[$model];
				}
			}
			foreach ($sources as $key => $value) {
				$cmd = $this->getCmd(null, $key);
				if (!is_object($cmd)) {
					$cmd = new denonavrCmd();
					$cmd->setLogicalId($key);
					$cmd->setName($value);
					$cmd->setIsVisible(1);
				}
				$cmd->setType('action');
				$cmd->setSubType('other');
				$cmd->setEqLogic_id($this->getId());
				$cmd->save();
			}
			$this->updateInfo();
		}
		
	}

	/**
	 * Convertit le nom interne d'une source renvoyé par GetRenameSource/GetDeletedSource
	 * (ex: "Blu-ray", "Media Player", "CBL/SAT", "TV AUDIO", "NETWORK")
	 * vers le code de commande Denon attendu par SI<code_source> (ex: "BD", "MPLAY", "SAT/CBL", "TV", "NET").
	 *
	 * @param string $_name Nom interne de la source dans AppCommand.xml
	 * @return string LogicalId de la commande d'entrée
	 */
	public static function mapHeosSourceToLogicalId($_name) {
		$map = array(
			'CBL/SAT' => 'SAT/CBL',
			'SAT/CBL' => 'SAT/CBL',
			'DVD' => 'DVD',
			'Blu-ray' => 'BD',
			'BD' => 'BD',
			'GAME' => 'GAME',
			'Game' => 'GAME',
			'AUX1' => 'AUX1',
			'AUX2' => 'AUX2',
			'AUX3' => 'AUX3',
			'AUX4' => 'AUX4',
			'AUX5' => 'AUX5',
			'AUX6' => 'AUX6',
			'AUX7' => 'AUX7',
			'Media Player' => 'MPLAY',
			'MPLAY' => 'MPLAY',
			'iPod/USB' => 'USB/IPOD',
			'USB/IPOD' => 'USB/IPOD',
			'CD' => 'CD',
			'TUNER' => 'TUNER',
			'Tuner' => 'TUNER',
			'NETWORK' => 'NET',
			'NET' => 'NET',
			'HEOS Music' => 'NET',
			'TV AUDIO' => 'TV',
			'TV Audio' => 'TV',
			'TV' => 'TV',
			'Bluetooth' => 'BT',
			'BT' => 'BT',
			'PHONO' => 'PHONO',
			'Phono' => 'PHONO',
		);
		$name = trim($_name);
		return isset($map[$name]) ? $map[$name] : $name;
	}

	/**
	 * Envoie une requête POST XML à l'endpoint /goform/AppCommand.xml (port 8080) des amplis Denon HEOS.
	 * Note : Le serveur HTTP des amplis Denon exige des retours à la ligne entre les balises XML
	 * et accepte au maximum 5 balises <cmd> par requête.
	 *
	 * @param array $_commands Liste des commandes AppCommand à interroger (max 5)
	 * @return SimpleXMLElement|false Objet XML de la réponse ou false si vide/invalide
	 */
	public function requestAppCommand($_commands = array()) {
		$xmlBody = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<tx>\n";
		foreach ($_commands as $cmd) {
			$xmlBody .= " <cmd id=\"1\">" . $cmd . "</cmd>\n";
		}
		$xmlBody .= "</tx>\n";
		$request_http = new com_http('http://' . $this->getConfiguration('ip') . ':8080/goform/AppCommand.xml');
		$request_http->setHeader(array('Content-Type: text/xml; charset="utf-8"'));
		$request_http->setPost($xmlBody);
		$result = trim($request_http->exec(5, 1));
		if ($result == '') {
			return false;
		}
		return @simplexml_load_string($result);
	}

	/**
	 * Récupère l'état complet de l'amplificateur sur les modèles de génération HEOS (ex: AVC-X8500H/HA)
	 * via le port 8080 (/goform/AppCommand.xml).
	 *
	 * @param bool $_includeSources Si true, récupère également la liste des sources actives et renommées
	 * @return array|void Tableau normalisé des informations de la zone configurée
	 */
	public function getHeosAmpInfo($_includeSources = false) {
		try {
			$xml = $this->requestAppCommand(array(
				'GetAllZonePowerStatus',
				'GetAllZoneSource',
				'GetAllZoneVolume',
				'GetAllZoneMuteStatus',
				'GetSurroundModeStatus',
			));
		} catch (Exception $e) {
			if ($this->getConfiguration('canBeShutdown') == 1) {
				return;
			}
			throw $e;
		}
		if ($xml === false || !isset($xml->cmd)) {
			return;
		}

		// Sélection de la balise XML correspondant à la zone configurée (zone1, zone2 ou zone3)
		$zoneConfig = $this->getConfiguration('zone', 'main');
		$zoneTag = 'zone1';
		if ($zoneConfig == '2') {
			$zoneTag = 'zone2';
		} else if ($zoneConfig == '3') {
			$zoneTag = 'zone3';
		}

		$data = array();
		if (isset($xml->cmd[0]->$zoneTag)) {
			$data['ZonePower'] = trim((string) $xml->cmd[0]->$zoneTag);
		}
		if (isset($xml->cmd[1]->$zoneTag->source)) {
			$data['InputFuncSelect'] = trim((string) $xml->cmd[1]->$zoneTag->source);
		}
		if (isset($xml->cmd[2]->$zoneTag->volume)) {
			$vol = trim((string) $xml->cmd[2]->$zoneTag->volume);
			if (is_numeric($vol)) {
				$data['MasterVolume'] = floatval($vol);
			}
		}
		if (isset($xml->cmd[3]->$zoneTag)) {
			$data['Mute'] = strtolower(trim((string) $xml->cmd[3]->$zoneTag));
		}
		if (isset($xml->cmd[4]->surround)) {
			$data['selectSurround'] = trim((string) $xml->cmd[4]->surround);
		}

		// Lors de la sauvegarde (postSave), récupère les noms personnalisés et les sources masquées/actives
		if ($_includeSources) {
			try {
				$xmlSources = $this->requestAppCommand(array(
					'GetRenameSource',
					'GetDeletedSource',
				));
				if ($xmlSources !== false && isset($xmlSources->cmd)) {
					$renames = array();
					$active = array();
					// 1. Noms personnalisés définis dans l'ampli (ex: Blu-ray -> "XBOX SX", GAME -> "PS5")
					if (isset($xmlSources->cmd[0]->functionrename->list)) {
						foreach ($xmlSources->cmd[0]->functionrename->list as $item) {
							$origName = trim((string) $item->name);
							$renameVal = trim((string) $item->rename);
							$renames[$origName] = ($renameVal !== '') ? $renameVal : $origName;
						}
					}
					// 2. Filtre des sources activées (<use>1</use>) et non masquées (<use>0</use>)
					if (isset($xmlSources->cmd[1]->functiondelete->list)) {
						foreach ($xmlSources->cmd[1]->functiondelete->list as $item) {
							$origName = trim((string) $item->name);
							$use = trim((string) $item->use);
							if ($use == '1') {
								$active[$origName] = true;
							}
						}
					}
					$sources = array();
					foreach ($renames as $origName => $displayName) {
						if (count($active) > 0 && !isset($active[$origName])) {
							continue;
						}
						$logicalId = self::mapHeosSourceToLogicalId($origName);
						$sources[$logicalId] = $displayName;
					}
					if (count($sources) > 0) {
						$data['Sources'] = $sources;
					}
				}
			} catch (Exception $e) {
				// En cas d'erreur de récupération des sources, postSave utilisera la liste par défaut
			}
		}
		return $data;
	}
	
	/**
	 * Récupère les informations d'état de l'amplificateur selon le mode d'API configuré
	 * (HEOS port 8080 ou Standard port 80 avec repli automatique sur HEOS).
	 *
	 * @param bool $_includeSources Si true, inclut la liste des sources disponibles (utilisé par postSave)
	 * @return array|void Tableau contenant ZonePower, InputFuncSelect, MasterVolume, Mute, selectSurround
	 */
	public function getAmpInfo($_includeSources = false) {
		if ($this->getConfiguration('mode') == 'H') {
			return $this->getHeosAmpInfo($_includeSources);
		}
		$zone = '';
		if ($this->getConfiguration('zone', 'main') == 2) {
			$zone = '?ZoneName=ZONE2';
		} else if ($this->getConfiguration('zone', 'main') == 3) {
			$zone = '?ZoneName=ZONE3';
		}
		$request_http = new com_http('http://' . $this->getConfiguration('ip') . '/goform/formMainZone_MainZoneXml.xml' . $zone);
		try {
			$result = trim($request_http->exec());
		} catch (Exception $e) {
			// Repli automatique sur le port 8080 (AppCommand.xml) si le port 80 ne répond pas
			try {
				$heosData = $this->getHeosAmpInfo($_includeSources);
				if (is_array($heosData) && count($heosData) > 0) {
					return $heosData;
				}
			} catch (Exception $e2) {
			}
			if ($this->getConfiguration('canBeShutdown') == 1) {
				return;
			} else {
				throw $e;
			}
		}
		$xml = simplexml_load_string($result);
		$data = json_decode(json_encode(simplexml_load_string($result)), true);
		$data['VideoSelectLists'] = array();
		if (isset($xml->VideoSelectLists) && is_array($xml->VideoSelectLists->value)) {
			foreach ($xml->VideoSelectLists->value as $VideoSelectList) {
				$data['VideoSelectLists'][(string) $VideoSelectList["index"]] = (string) $VideoSelectList;
			}
		}
		foreach ($data as $key => $value) {
			if (isset($value['value'])) {
				$data[$key] = $value['value'];
			}
		}
		return $data;
	}
	
	/**
	 * Interroge l'amplificateur et met à jour les commandes info Jeedom si leurs valeurs ont changé.
	 */
	public function updateInfo() {
		if ($this->getConfiguration('ip') == '') {
			return;
		}
		try {
			$infos = $this->getAmpInfo();
		} catch (Exception $e) {
			return;
		}
		if (!is_array($infos)) {
			return;
		}
		if (isset($infos['ZonePower'])) {
			$this->checkAndUpdateCmd('power_state', ($infos['ZonePower'] == 'OFF' || $infos['ZonePower'] == 'STANDBY') ? 0 : 1);
		}
		if (isset($infos['InputFuncSelect'])) {
			$this->checkAndUpdateCmd('input', $infos['InputFuncSelect']);
		}
		if (isset($infos['MasterVolume'])) {
			$this->checkAndUpdateCmd('volume', $infos['MasterVolume']);
		}
		if (isset($infos['Mute'])) {
			$this->checkAndUpdateCmd('mute_state', ($infos['Mute'] == 'on') ? 1 : 0);
		}
		if (isset($infos['selectSurround'])) {
			$this->checkAndUpdateCmd('sound_mode', $infos['selectSurround']);
		}
	}
	
	/*     * **********************Getteur Setteur*************************** */
}

class denonavrCmd extends cmd {
	/*     * *************************Attributs****************************** */
	
	/*     * ***********************Methode static*************************** */
	
	/*     * *********************Methode d'instance************************* */
	
	/**
	 * Exécute une commande action Jeedom vers l'amplificateur Denon :
	 * - En mode HEOS ('H') : utilise /goform/formiPhoneAppDirect.xml sur le port 8080 (Zones 1, 2 et 3).
	 * - En mode Standard ('') : utilise /MainZone/index.put.asp sur le port 80.
	 *
	 * @param array $_options Paramètres de la commande (ex: $_options['slider'] pour le volume)
	 */
	public function execute($_options = array()) {
		$eqLogic = $this->getEqLogic();
		// La commande 'refresh' met uniquement à jour les états sans envoyer d'ordre à l'ampli
		if ($this->getLogicalId() == 'refresh') {
			$eqLogic->updateInfo();
			return;
		}
		$zoneConfig = $eqLogic->getConfiguration('zone', 'main');
		$zone = '';
		if ($zoneConfig == 2) {
			$zone = '&ZoneName=ZONE2';
		} else if ($zoneConfig == 3) {
			$zone = '&ZoneName=ZONE3';
		}
		if ($eqLogic->getConfiguration('mode') == 'H') {
			// Préfixes de commandes Denon selon la zone ciblée (Main = ZM/MV/MU/SI, Zone 2 = Z2, Zone 3 = Z3)
			$zonePrefix = 'ZM';
			$volPrefix = 'MV';
			$mutePrefix = 'MU';
			$inputPrefix = 'SI';
			if ($zoneConfig == 2) {
				$zonePrefix = 'Z2';
				$volPrefix = 'Z2';
				$mutePrefix = 'Z2MU';
				$inputPrefix = 'Z2';
			} else if ($zoneConfig == 3) {
				$zonePrefix = 'Z3';
				$volPrefix = 'Z3';
				$mutePrefix = 'Z3MU';
				$inputPrefix = 'Z3';
			}

			$directCmd = null;
			if ($this->getLogicalId() == 'on') {
				$directCmd = $zonePrefix . 'ON';
			} else if ($this->getLogicalId() == 'off') {
				$directCmd = $zonePrefix . 'OFF';
			} else if ($this->getLogicalId() == 'volume_set') {
				$slider = floatval($_options['slider']);
				// Conversion dB (-79 à +19) -> échelle absolue Denon (00 à 98, où 80 correspond à 0.0 dB)
				$absVol = round($slider + 80);
				if ($absVol < 0) {
					$absVol = 0;
				} else if ($absVol > 98) {
					$absVol = 98;
				}
				$directCmd = $volPrefix . sprintf('%02d', $absVol);
			} else if ($this->getLogicalId() == 'mute') {
				// Bascule (toggle) du mode muet en fonction du dernier état connu (mute_state)
				$muteCmd = $eqLogic->getCmd(null, 'mute_state');
				$isMuted = (is_object($muteCmd) && $muteCmd->execCmd() == 1);
				$directCmd = $mutePrefix . ($isMuted ? 'OFF' : 'ON');
			} else {
				// Sélection d'une source d'entrée (ex: SIBD, SIGAME, SIMPLAY, SISAT/CBL, SITV, SINET...)
				$sourceId = $this->getLogicalId();
				if ($sourceId == 'NETHOME') {
					$sourceId = 'NET';
				}
				$directCmd = $inputPrefix . str_replace(' ', '%20', $sourceId);
			}

			if ($directCmd !== null) {
				$request_http = new com_http('http://' . $eqLogic->getConfiguration('ip') . ':8080/goform/formiPhoneAppDirect.xml?' . $directCmd);
				$request_http->exec(60);
			}
		} else {
			// Mode API Standard (anciens amplis Denon sur le port 80)
			if ($this->getLogicalId() == 'on') {
				$request_http = new com_http('http://' . $eqLogic->getConfiguration('ip') . '/MainZone/index.put.asp?cmd0=PutZone_OnOff%2FON' . $zone);
			} else if ($this->getLogicalId() == 'off') {
				$request_http = new com_http('http://' . $eqLogic->getConfiguration('ip') . '/MainZone/index.put.asp?cmd0=PutZone_OnOff%2FOFF' . $zone);
			} else if ($this->getLogicalId() == 'volume_set') {
				$request_http = new com_http('http://' . $eqLogic->getConfiguration('ip') . '/MainZone/index.put.asp?cmd0=PutMasterVolumeSet%2F' . $_options['slider'] . $zone);
			} else if ($this->getLogicalId() == 'mute') {
				$request_http = new com_http('http://' . $eqLogic->getConfiguration('ip') . '/MainZone/index.put.asp?cmd0=PutVolumeMute/TOGGLE');
			} else {
				$request_http = new com_http('http://' . $eqLogic->getConfiguration('ip') . '/MainZone/index.put.asp?cmd0=PutZone_InputFunction%2F' . $this->getLogicalId() . $zone);
			}
			$request_http->exec(60);
		}
		// Pause d'une seconde pour laisser l'ampli appliquer la commande avant de relire son état
		sleep(1);
		$eqLogic->updateInfo();
	}
	
	/*     * **********************Getteur Setteur*************************** */
}

?>
