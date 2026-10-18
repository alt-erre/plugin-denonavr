# Plugin Denon AVR (`denonavr`) pour Jeedom

Plugin Jeedom permettant de piloter et de superviser les amplificateurs Home-Cinéma **Denon** en réseau local (gammes historiques et génération **HEOS** comme l'AVC-X8500H / AVC-X8500HA).

---

## Fonctionnalités

- **Double compatibilité API** :
  - **Mode Standard (port `80`)** : pour les amplificateurs Denon d'ancienne génération via `/goform/formMainZone_MainZoneXml.xml` et `/MainZone/index.put.asp`.
  - **Mode HEOS (port `8080`)** : pour les amplificateurs Denon récents (gamme `-H` / `-HA`) via `/goform/AppCommand.xml` (requêtes XML POST) et `/goform/formiPhoneAppDirect.xml`.
- **Gestion Multi-Zones** :
  - Prise en charge de la **Zone Principale**, de la **Zone 2** et de la **Zone 3**.
- **Découverte dynamique des entrées (Mode HEOS)** :
  - Récupération automatique des sources réellement activées sur l'amplificateur (`GetDeletedSource`) et de leurs **noms personnalisés** (`GetRenameSource`) lors de la sauvegarde de l'équipement.
- **Commandes et retours d'état** :
  - État d'alimentation (`On` / `Off` / `Etat`)
  - Volume avec conversion automatique dB (`-79` à `+19 dB`) vers l'échelle absolue Denon (`00` à `98`)
  - Gestion de la sourdine (`Muet` en bascule On/Off et `Etat Muet`)
  - Sélection de la source d'entrée et retour d'état (`Entrée`)
  - Retour du mode audio / surround actif (`Audio`)

---

## Crédits et Licence

- **Auteur original** : **Loïc** (Jeedom SAS)
- **Support HEOS (AVC-X8500H/HA), multi-zones, sources dynamiques et documentation** : **Aurélien Léovant** ([alt-erre](https://github.com/alt-erre))
- **Licence** : Distribué sous licence **AGPL** (voir [`LICENSE`](LICENSE) et [`plugin_info/info.json`](plugin_info/info.json)).