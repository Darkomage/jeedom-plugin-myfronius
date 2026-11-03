# Changelog — MyFronius

> **IMPORTANT**
>
> S'il n'y a pas d'information sur la mise à jour, c'est que celle-ci concerne uniquement de la mise à jour de documentation, de traduction ou de texte.

> Historique des versions du plugin MyFronius.
## 1.0.0-beta — 2026-11-03

Première version publique.

### Supervision
- **Équipements créés par la synchronisation** : Site PV, Batterie, Onduleur et Smart Meter (plusieurs onduleurs et compteurs pris en charge), lus par la Solar API, avec un mode standard ou expert par équipement.
- **Flux instantanés** : production PV, consommation maison, réseau et batterie, surplus disponible avec ou sans la batterie, autonomie et autoconsommation ; prise en charge du mode secours (réseau coupé).
- **Tendances** : moyennes glissantes sur 1, 5 et 15 minutes et pente sur 5 minutes de chaque flux et des surplus, pour des scénarios sans yo-yo.
- **Énergies du jour** : production PV, import, export, consommation, charge et décharge de la batterie, taux d'autoconsommation et d'autonomie du jour ; remise à zéro à minuit (heure de Jeedom), conservées au redémarrage du démon et de la box.
- **Batterie** : état de charge, mode Fronius, sens (charge, décharge, repos), temps estimés avant pleine et avant vide, tension, courant et température.
- **Onduleur** : mesures AC, puissance DC par MPPT, énergies, état et code erreur.
- **Smart Meter** : puissances, tensions et courants par phase, compteurs d'import et d'export.
- **Historisation par défaut** adaptée à chaque commande, posée à la création seulement, avec un bouton « Restaurer les défauts d'historisation ».
- **Démon Python** : cadence de lecture réglable (60 s au plus), reprise automatique après une perte de l'onduleur, bouton « Tester la connexion ».

### Pilotage (optionnel)
- **Charges pilotées sur le surplus** : n'importe quel appareil de Jeedom (puissance, état, allumer, éteindre), allumé et éteint selon sa priorité, une marge de surplus, des délais et des durées minimales ; autorisation par commande ou par formule, forçage marche / arrêt, raison de l'état, énergie absorbée du jour, actions supplémentaires à l'allumage et à l'extinction, effacement au branchement d'une voiture sur une Wattpilot.
- **Batterie par Modbus TCP** : gestion automatique, bloquer la charge ou la décharge, forcer la charge depuis le réseau, limiter la charge, réserve minimale, bouton « Tester Modbus ». Sécurités : retour automatique de l'onduleur après 120 s sans consigne, consignes refusées en mode secours, adresse des registres revérifiée avant chaque écriture.
- **Bornes Fronius Wattpilot** (seules bornes prises en charge à ce jour) : ajout par adresse et mot de passe, état en direct, mode et forçage, courant maximal, énergie, distance et heure du prochain trajet ; mot de passe chiffré dans Jeedom.

### Affichage
- **Widget « Site PV »** (dashboard et mobile) : flux animés, batterie, charges pilotées, indicateurs et pastilles d'état, boutons de pilotage de la batterie, thèmes clair et sombre.
- **Widget « Borne »** (dashboard et mobile) : état, mode, forçage et réglages de la borne.

