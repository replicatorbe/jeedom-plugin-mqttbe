# Changelog

## 0.1 — 2026-09-17

Première version, limitée à la liaison avec le broker.

**Ce qu'elle fait**

- Le plugin s'installe, s'affiche et ne perturbe rien : c'est le jalon 0.
- Un démon autonome se connecte au broker MQTT, tient la connexion et se
  reconnecte tout seul quand elle tombe : c'est le jalon 1.
- La page du plugin montre deux états distincts, celui du démon et celui du
  broker, mis à jour sans recharger la page. Un démon en marche et un broker
  déconnecté désignent une adresse, un port ou des identifiants à revoir, et le
  motif du refus est affiché.
- La configuration ne demande que l'adresse du broker. Le chiffrement TLS,
  l'authentification et les topics ignorés sont réglables, mais leurs valeurs
  par défaut conviennent.
- Un bouton **Tester la connexion** ouvre une vraie connexion vers le broker et
  rend son verdict, sans attendre le démarrage du démon.
- L'identifiant client présenté au broker est engendré une fois à l'installation
  puis mémorisé : deux clients de même identifiant se déconnecteraient l'un
  l'autre en boucle.

**Ce qu'elle ne fait pas encore**

Aucune découverte, aucun équipement, aucune commande. La découverte automatique
— les Shelly d'abord, puis les Tasmota, Zigbee2MQTT et Home Assistant Discovery
— arrive au jalon suivant.
