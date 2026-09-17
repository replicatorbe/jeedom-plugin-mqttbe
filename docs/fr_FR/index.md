# Plugin MQTT BE

Ce plugin relie Jeedom à un broker MQTT et découvre tout seul les équipements
qui s'y annoncent. Vous n'avez rien à décrire : ni topic à recopier, ni modèle
d'équipement à écrire. Le plugin écoute ce qui circule réellement sur le broker
et en déduit les équipements et leurs commandes.

Les Shelly sont pris en charge en premier, Gen1 comme Gen2 et suivantes. Viennent
ensuite les Tasmota, les passerelles Zigbee2MQTT, et tout matériel parlant le
protocole d'annonce de Home Assistant.

## Ce qu'il faut configurer

**L'adresse du broker, et rien d'autre.** C'est le seul réglage indispensable.

1. Installez le plugin, puis activez-le.
2. Ouvrez sa configuration (la roue dentée).
3. Saisissez l'adresse de votre broker MQTT — l'adresse IP de la machine qui
   fait tourner Mosquitto, par exemple.
4. Cliquez sur **Tester la connexion**. Le plugin se connecte au broker et vous
   dit ce qu'il a obtenu.
5. Sauvegardez.

Le port est déjà rempli avec 1883, le port d'usage d'un broker en clair. Si votre
broker demande un identifiant et un mot de passe, renseignez-les ; beaucoup de
brokers domestiques acceptent les connexions anonymes et il n'y a alors rien à
saisir.

Les autres réglages — chiffrement TLS, certificat d'autorité, port des ordres du
démon, topics ignorés — ont des valeurs qui conviennent telles quelles. N'y
touchez que si vous savez pourquoi.

### Un mot sur le chiffrement

MQTT transmet le mot de passe en clair quand TLS n'est pas activé. Sur un réseau
domestique fermé, cela n'a rien de dramatique. Sur un réseau partagé, activez
TLS — et pensez à changer le port, un broker en TLS écoute rarement sur 1883.

L'option **Ne pas vérifier le certificat** laisse le chiffrement actif mais
cesse de contrôler l'identité du broker : n'importe quel serveur peut alors se
faire passer pour lui. Elle existe pour le certificat auto-signé d'un broker
domestique, pas pour se débarrasser d'une erreur que l'on n'a pas lue.

## Ce que vous voyez sur la page du plugin

Deux pastilles, et elles ne disent pas la même chose.

**Démon** indique si le processus qui parle au broker tourne. **Broker** indique
si ce processus est effectivement connecté. Un démon en marche avec un broker
déconnecté est le cas courant d'une mauvaise adresse, d'un mauvais port ou
d'identifiants refusés : le motif du refus s'affiche en survolant la pastille.

Les deux pastilles se mettent à jour toutes seules, sans recharger la page.

## État d'avancement

Le plugin en est à ses deux premiers jalons :

- **Jalon 0** — le plugin s'installe, s'affiche et ne perturbe rien.
- **Jalon 1** — le démon se connecte au broker, tient la connexion, se
  reconnecte quand elle tombe, et l'interface montre son état.

**Aucun équipement n'est découvert ni créé pour le moment.** La page du plugin
reste donc vide d'équipements, et c'est normal : la découverte automatique arrive
au jalon suivant. Ce que vous pouvez vérifier dès aujourd'hui, c'est que la
liaison avec votre broker s'établit et qu'elle tient.

## En cas de problème

Le journal du plugin s'appelle `mqttbe`, celui du démon `mqttbed`. Tous deux se
consultent depuis **Analyse → Logs**.

Si la pastille du broker reste rouge, vérifiez dans l'ordre : l'adresse, le port,
puis les identifiants. Depuis une console sur la machine Jeedom, un client MQTT
tranche la question en une commande :

```bash
mosquitto_sub -h 192.168.1.10 -p 1883 -t '#' -v
```

Si cette commande ne reçoit rien, le plugin ne recevra rien non plus, et ce n'est
pas de son côté qu'il faut chercher.
