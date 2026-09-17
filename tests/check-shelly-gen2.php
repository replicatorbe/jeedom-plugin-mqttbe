<?php
/* Contrôles de l'adapter de découverte Shelly Gen2 / Gen3 / Gen4.
 *
 * CE QUE CES CONTRÔLES PROUVENT, ET CE QU'ILS NE PROUVENT PAS.
 *
 * Ils rejouent des conversations RPC reconstituées d'après la documentation
 * officielle, faute d'appareil sous tension au moment de les écrire. Un jeu de
 * données écrit d'après une lecture de la documentation confirme donc la
 * compréhension qu'on en a, y compris quand elle est fausse : ces contrôles
 * établissent que l'adapter fait ce qu'on a voulu, jamais qu'un Shelly réel
 * répond ainsi. C'est écrit ici pour que personne ne s'y trompe, et la
 * validation sur matériel reste à faire.
 *
 * Ce qu'ils attrapent, en revanche, est bien réel, et rien de tout cela ne se
 * voit à la relecture :
 *   - un appareil sur pile interrogé alors qu'il dort : trois questions, trois
 *     délais d'expiration, et pas une ligne de réponse ;
 *   - un volet devenu deux interrupteurs, ou l'inverse ;
 *   - une énergie en watt-heures affichée sous une étiquette « kWh » : trois
 *     ordres de grandeur, et un utilisateur qui conclut, à raison, que le
 *     plugin est faux ;
 *   - un appareil découvert deux fois, une fois par cet adapter et une fois par
 *     celui de la génération 1, parce que les deux écoutent `shellies/announce` ;
 *   - une commande dont le topic contiendrait un joker, ce que le broker
 *     refuserait à chaque appui ;
 *   - un événement de bouton retenu, rejoué au démarrage du démon, qui
 *     déclencherait un scénario que personne n'a demandé.
 *
 * Les captures sont anonymisées (SSID, IP en 192.0.2.x, MAC et identifiants
 * fabriqués) : le dépôt part sur GitHub. */

require_once __DIR__ . '/outils.php';

function mqttbeCheminAdapterGen2() {
    return mqttbeRacine() . '/resources/mqttbed/discovery/adapters/ShellyGen2.php';
}

function mqttbeCheminCatalogueGen2() {
    return mqttbeRacine() . '/core/config/catalog/shelly-gen2.json';
}

function mqttbeLitJsonGen2($_nom) {
    $chemin = mqttbeRacine() . '/tests/fixtures/shelly/gen2/' . $_nom;
    if (!is_readable($chemin)) {
        return null;
    }
    $decode = json_decode(file_get_contents($chemin), true);
    return is_array($decode) ? $decode : null;
}

/* --------------------------------------------------------------------------
 * Contexte de papier
 *
 * Le contrat ne donne à l'adapter que les méthodes de MqttbeDiscoveryContext, et
 * pas une de plus : un contexte d'essai plus riche que le vrai laisserait passer
 * un adapter qui s'appuie sur ce que le moteur ne lui donnera pas.
 *
 * La mémoire est BORNÉE comme celle du moteur. Sans cette borne, un adapter qui
 * mémorise par topic vu au lieu de mémoriser par appareil passerait tous les
 * contrôles ici et ferait déborder la vraie mémoire en production, sur un parc
 * dont personne n'aurait prévu la taille.
 * ------------------------------------------------------------------------ */
class MqttbeContexteEssaiGen2 {

    const MEMOIRE_MAX = 512;

    public $publications = array();
    public $abonnements  = array();
    public $modeles      = array();
    public $journal      = array();
    public $refus        = 0;

    private $memoire = array();
    private $horloge = 1700000000.0;

    public $broker = true;

    public function publish($_topic, $_payload, $_qos = 0, $_retain = false) {
        if (!$this->broker) {
            return false;
        }
        $this->publications[] = array('topic' => $_topic, 'payload' => $_payload);
        return true;
    }

    public function subscribe($_topic) {
        $this->abonnements[] = $_topic;
        return true;
    }

    public function remember($_cle, $_donnees) {
        if (!array_key_exists($_cle, $this->memoire) && count($this->memoire) >= self::MEMOIRE_MAX) {
            $this->refus++;
            return false;
        }
        $this->memoire[$_cle] = $_donnees;
        return true;
    }

    public function recall($_cle) {
        return array_key_exists($_cle, $this->memoire) ? $this->memoire[$_cle] : null;
    }

    public function forget($_cle) {
        unset($this->memoire[$_cle]);
    }

    public function memoryCount() {
        return count($this->memoire);
    }

    public function emit($_modele) {
        $this->modeles[] = $_modele;
        return true;
    }

    public function log($_niveau, $_message) {
        $this->journal[] = $_niveau . ' : ' . $_message;
    }

    public function now() {
        return $this->horloge;
    }

    public $relance = false;

    public function rescan() {
        $demandee = $this->relance;
        $this->relance = false;
        return $demandee;
    }

    /* -- commodités du contrôle, jamais vues par l'adapter ----------------- */

    public function avance($_secondes) {
        $this->horloge += $_secondes;
    }

    /* La dernière requête RPC publiée sur un topic donné, décodée. */
    public function derniereRequete($_topic = null) {
        for ($i = count($this->publications) - 1; $i >= 0; $i--) {
            $publication = $this->publications[$i];
            if (substr($publication['topic'], -4) !== '/rpc') {
                continue;
            }
            if ($_topic !== null && $publication['topic'] !== $_topic) {
                continue;
            }
            $decode = json_decode($publication['payload'], true);
            if (is_array($decode)) {
                $decode['_topic'] = $publication['topic'];
                return $decode;
            }
        }
        return null;
    }

    public function requetesVers($_prefixe) {
        $compte = 0;
        foreach ($this->publications as $publication) {
            if ($publication['topic'] === $_prefixe . '/rpc') {
                $compte++;
            }
        }
        return $compte;
    }

    public function demandesAnnonce() {
        $compte = 0;
        foreach ($this->publications as $publication) {
            if ($publication['topic'] === 'shellies/command' && $publication['payload'] === 'announce') {
                $compte++;
            }
        }
        return $compte;
    }
}

/* --------------------------------------------------------------------------
 * Rejeu d'une conversation
 * ------------------------------------------------------------------------ */

define('MQTTBE_SRC_GEN2', 'mqttbe-essai');

function mqttbeAdapterGen2() {
    return new MqttbeShellyGen2(mqttbeCheminCatalogueGen2(), MQTTBE_SRC_GEN2);
}

/* Répond à la dernière question posée, comme le ferait l'appareil : sur le topic
 * que le `src` de la requête désigne, avec le même numéro, et en recopiant ce
 * `src` dans `dst`. */
function mqttbeRepondGen2($_adapter, $_ctx, $_prefixe, $_identifiant, $_resultat, $_erreur = null) {
    $requete = $_ctx->derniereRequete($_prefixe . '/rpc');
    if ($requete === null) {
        return null;
    }
    $reponse = array('id' => $requete['id'], 'src' => $_identifiant, 'dst' => $requete['src']);
    if ($_erreur !== null) {
        $reponse['error'] = $_erreur;
    } else {
        $reponse['result'] = $_resultat;
    }
    $_adapter->onMessage($requete['src'] . '/rpc', json_encode($reponse), false, $_ctx);
    return $requete;
}

/*
 * Rejoue un appareil de bout en bout : il se signale par son `online` retenu,
 * l'adapter l'interroge, l'appareil répond.
 *
 * Trois chemins, tels qu'ils se présentent sur le terrain : l'appareil bavard
 * qui répond à tout, l'appareil au micrologiciel ancien qui ne connaît pas
 * `Shelly.GetComponents`, et l'appareil sur pile qui ne répond à rien et se
 * contente de pousser son état complet à son réveil.
 */
function mqttbeRejoueGen2($_adapter, $_ctx, $_appareil) {
    $prefixe = $_appareil['prefixe'];
    $identifiant = $_appareil['deviceinfo']['id'];

    $_adapter->onTick($_ctx);

    if (!empty($_appareil['muet'])) {
        /* Un appareil sur pile : il ne se signale que par sa trame complète. */
        $_adapter->onMessage($prefixe . '/events/rpc',
            json_encode($_appareil['notifyfullstatus']), false, $_ctx);
        $_adapter->onTick($_ctx);
        return;
    }

    $_adapter->onMessage($prefixe . '/online', 'true', true, $_ctx);
    $_adapter->onTick($_ctx);
    mqttbeRepondGen2($_adapter, $_ctx, $prefixe, $identifiant, $_appareil['deviceinfo']);
    $_adapter->onTick($_ctx);

    if (!empty($_appareil['sans_getcomponents'])) {
        /* Le 404 « No handler for … » d'un micrologiciel antérieur à la 1.x,
         * puis le repli : GetStatus, puis GetConfig. */
        mqttbeRepondGen2($_adapter, $_ctx, $prefixe, $identifiant, null,
            array('code' => 404, 'message' => 'No handler for Shelly.GetComponents'));
        $_adapter->onTick($_ctx);
        mqttbeRepondGen2($_adapter, $_ctx, $prefixe, $identifiant, $_appareil['status']);
        $_adapter->onTick($_ctx);
        mqttbeRepondGen2($_adapter, $_ctx, $prefixe, $identifiant, $_appareil['config']);
        $_adapter->onTick($_ctx);
        return;
    }

    /* Pagination : la taille d'une page est imposée par l'appareil, jamais
     * choisie par nous. On découpe donc l'inventaire en autant de pages que la
     * capture en déclare, et l'adapter doit toutes les demander. */
    $composants = $_appareil['composants'];
    $pages = isset($_appareil['pages']) ? max(1, (int) $_appareil['pages']) : 1;
    $parPage = (int) ceil(count($composants) / $pages);
    $offset = 0;
    $garde = 0;
    while ($offset < count($composants) && $garde++ < 20) {
        $tranche = array_slice($composants, $offset, $parPage);
        mqttbeRepondGen2($_adapter, $_ctx, $prefixe, $identifiant, array(
            'components' => $tranche,
            'cfg_rev'    => 26,
            'offset'     => $offset,
            'total'      => count($composants),
        ));
        $offset += count($tranche);
        $_adapter->onTick($_ctx);
    }
}

function mqttbeModelesParUidGen2($_ctx) {
    $modeles = array();
    foreach ($_ctx->modeles as $modele) {
        $modeles[$modele->uid()] = $modele;
    }
    return $modeles;
}

/* Le modèle d'un appareil de la capture, rejoué seul. */
function mqttbeModeleGen2($_appareil, $_ctx = null) {
    $ctx = ($_ctx === null) ? new MqttbeContexteEssaiGen2() : $_ctx;
    $adapter = mqttbeAdapterGen2();
    mqttbeRejoueGen2($adapter, $ctx, $_appareil);
    return empty($ctx->modeles) ? null : $ctx->modeles[count($ctx->modeles) - 1];
}

function mqttbeCanauxGen2($_modele) {
    $canaux = array();
    foreach ($_modele->channels() as $canal) {
        $canaux[$canal->key()] = $canal;
    }
    return $canaux;
}

function mqttbeClesGen2($_modele) {
    $cles = array_keys(mqttbeCanauxGen2($_modele));
    sort($cles);
    return $cles;
}

/* ==========================================================================
 * Les contrôles
 * ======================================================================= */
function mqttbeControlesShellyGen2() {
    $resultats = array();

    $adapterPresent = is_readable(mqttbeCheminAdapterGen2());
    $captures = mqttbeLitJsonGen2('conversations.json');
    if (!$adapterPresent || $captures === null || !isset($captures['appareils'])) {
        return array(mqttbeIndecis('adapter Shelly Gen2+', !$adapterPresent
            ? 'resources/mqttbed/discovery/adapters/ShellyGen2.php n\'existe pas encore.'
            : 'les captures de tests/fixtures/shelly/gen2 sont absentes ou illisibles.'));
    }

    require_once mqttbeRacine() . '/resources/mqttbed/discovery/Channel.php';
    require_once mqttbeRacine() . '/resources/mqttbed/discovery/DeviceModel.php';
    require_once mqttbeCheminAdapterGen2();

    /* Sans vocabulaire chargé, validate() ne peut pas dénoncer une capacité
     * inventée : le contrôle correspondant se déclarerait indécis. */
    $vocabulaireCharge = MqttbeChannel::loadCapabilities(
        mqttbeRacine() . '/core/config/capabilities.json');

    $appareils = $captures['appareils'];

    /* ---------------------------------------------------------------------
     * 1. Le contrat d'adapter
     * ------------------------------------------------------------------- */
    $titre = 'contrat d\'adapter : identité, priorité, construction sans argument';
    $fautes = array();
    $adapter = mqttbeAdapterGen2();
    if (!($adapter instanceof MqttbeAdapter)) {
        $fautes[] = 'la classe n\'implémente pas MqttbeAdapter : le moteur ne la chargera jamais.';
    }
    if ($adapter->id() !== 'shelly.gen2') {
        $fautes[] = 'id() rend « ' . $adapter->id() .' » et non « shelly.gen2 » — c\'est cet '
            . 'identifiant que l\'interface traduit en « Shelly Gen2+ », et qui voyage dans '
            . 'mqttbe::adapter ; le changer fait oublier à Jeedom qui a découvert quoi.';
    }
    if ($adapter->priority() !== 100) {
        $fautes[] = 'priority() rend ' . $adapter->priority() . ' et non 100 : la découverte '
            . 'native du constructeur doit primer sur toute déduction générique.';
    }
    $reflexion = new ReflectionClass('MqttbeShellyGen2');
    $constructeur = $reflexion->getConstructor();
    if ($constructeur !== null && $constructeur->getNumberOfRequiredParameters() > 0) {
        $fautes[] = 'le constructeur exige ' . $constructeur->getNumberOfRequiredParameters()
            . ' argument(s) : le moteur n\'instancie que ce qui se construit sans rien.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 2. Des abonnements ciblés
     *
     * S'abonner à « # » ferait traverser la découverte par la totalité du
     * trafic du broker. Et le préfixe d'un Gen2 peut contenir des barres
     * obliques — la documentation ne l'interdit pas —, donc un seul niveau ne
     * suffit pas non plus.
     * ------------------------------------------------------------------- */
    $titre = 'abonnements : ciblés, multi-niveaux, sans « # »';
    $fautes = array();
    $abonnements = $adapter->subscriptions();
    foreach ($abonnements as $filtre) {
        if (strpos($filtre, '#') !== false) {
            $fautes[] = 'filtre « ' . $filtre . ' » : le joker « # » ferait passer tout le '
                . 'trafic du broker par la découverte.';
        }
    }
    $attendus = array('shellies/announce', MQTTBE_SRC_GEN2 . '/rpc',
                      '+/events/rpc', '+/+/events/rpc', '+/+/+/events/rpc',
                      '+/online', '+/+/online', '+/+/+/online');
    foreach ($attendus as $attendu) {
        if (!in_array($attendu, $abonnements, true)) {
            $fautes[] = 'abonnement manquant : « ' . $attendu . ' ».';
        }
    }
    if (count($abonnements) !== count(array_unique($abonnements))) {
        $fautes[] = 'un même filtre est demandé deux fois.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "un abonnement trop large coûte à chaque message du broker ; un abonnement trop étroit "
        . "rend invisible un appareil au préfixe personnalisé.\n" . implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 3. Le topic de réponse est unique à cette instance
     *
     * `<src>/rpc` n'est préfixé par rien : il est partagé par tout ce qui
     * l'écoute. Un `src` générique — le « user_1 » de tous les exemples de la
     * documentation, donc celui que tout le monde recopie — ferait recevoir à
     * deux Jeedom les réponses l'un de l'autre, sans moyen de les distinguer.
     * ------------------------------------------------------------------- */
    $titre = 'le topic de réponse RPC est propre à cette instance';
    $fautes = array();
    $a1 = new MqttbeShellyGen2(mqttbeCheminCatalogueGen2());
    $a2 = new MqttbeShellyGen2(mqttbeCheminCatalogueGen2());
    if ($a1->source() === $a2->source()) {
        $fautes[] = 'deux instances partagent le même « src » (' . $a1->source() . ') : sur un '
            . 'broker où tournent deux Jeedom, chacun recevrait les réponses de l\'autre.';
    }
    foreach (array($a1->source(), $a2->source()) as $src) {
        if (preg_match('/[#+\s]/', $src) || $src === '') {
            $fautes[] = 'le « src » « ' . $src . ' » ne peut pas composer un topic.';
        }
        if (in_array(strtolower($src), array('user_1', 'mynewtopic', 'shellies'), true)) {
            $fautes[] = 'le « src » « ' . $src . ' » est celui des exemples de la documentation : '
                . 'tout le monde le recopie, et les réponses se mélangeraient.';
        }
    }
    if (!in_array($a1->topicReponses(), $a1->subscriptions(), true)) {
        $fautes[] = 'l\'adapter n\'écoute pas son propre topic de réponse : aucun appel RPC '
            . 'n\'aboutirait jamais.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 4. Le parc entier se découvre
     * ------------------------------------------------------------------- */
    $titre = 'toutes les familles de la capture donnent un modèle valide';
    $fautes = array();
    $modeles = array();
    foreach ($appareils as $nom => $appareil) {
        $ctx = new MqttbeContexteEssaiGen2();
        $modele = mqttbeModeleGen2($appareil, $ctx);
        if ($modele === null) {
            $fautes[] = $nom . ' : aucun modèle émis.';
            continue;
        }
        $modeles[$nom] = $modele;
        $refus = $modele->validate();
        if (!empty($refus)) {
            $fautes[] = $nom . ' : ' . implode(' ', $refus);
        }
        if ($modele->countChannels() < 2) {
            $fautes[] = $nom . ' : ' . $modele->countChannels() . ' canal — un équipement sans '
                . 'commande n\'apprend rien à personne.';
        }
        if ($ctx->refus > 0) {
            $fautes[] = $nom . ' : ' . $ctx->refus . ' écriture(s) en mémoire refusée(s) — '
                . 'l\'adapter mémorise par topic vu et non par appareil.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 5. L'identité est celle de la génération 1
     *
     * `shelly:<mac>` en minuscules, exactement comme l'adapter Gen1 : c'est ce
     * qui fait qu'un parc mixte, ou un appareil vu par les deux adapters, ne
     * produit jamais deux équipements.
     * ------------------------------------------------------------------- */
    $titre = 'uid « shelly:<mac> », commun aux deux générations';
    $fautes = array();
    foreach ($modeles as $nom => $modele) {
        $mac = strtolower($appareils[$nom]['deviceinfo']['mac']);
        if ($modele->uid() !== 'shelly:' . $mac) {
            $fautes[] = $nom . ' : uid « ' . $modele->uid() . ' » au lieu de « shelly:' . $mac . ' ».';
        }
        if ($modele->uid() !== strtolower($modele->uid())) {
            $fautes[] = $nom . ' : uid en majuscules — deux orthographes donneraient deux équipements.';
        }
        if (!in_array('mac:' . $mac, $modele->aliases(), true)) {
            $fautes[] = $nom . ' : l\'alias « mac:' . $mac . ' » manque.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre,
        "l'uid se dérive de la MAC et de rien d'autre : ni du préfixe de topic, ni du nom, tous "
        . "deux modifiables par leur propriétaire.\n" . implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 6. UN APPAREIL SUR PILE NE S'INTERROGE PAS
     *
     * Il dort. Une question posée à une porte close, c'est un délai
     * d'expiration ; trois tentatives, c'est trois délais, et une découverte
     * qui traîne sur chaque capteur du parc. Son `NotifyFullStatus` dit déjà
     * tout ce qu'on peut savoir de lui.
     *
     * Et sa disponibilité ne doit PAS être déduite de `online` : sa session
     * MQTT est coupée à chaque sommeil, le testament part, et Jeedom déclarerait
     * hors ligne un capteur qui va parfaitement bien — vingt-trois heures sur
     * vingt-quatre.
     * ------------------------------------------------------------------- */
    $titre = 'appareil sur pile : jamais interrogé, jamais déclaré hors ligne';
    $fautes = array();
    foreach ($appareils as $nom => $appareil) {
        if (empty($appareil['sur_pile'])) {
            continue;
        }
        $ctx = new MqttbeContexteEssaiGen2();
        $adapter = mqttbeAdapterGen2();
        mqttbeRejoueGen2($adapter, $ctx, $appareil);
        /* On laisse passer largement de quoi consommer les trois tentatives. */
        for ($tour = 0; $tour < 8; $tour++) {
            $ctx->avance(10);
            $adapter->onTick($ctx);
        }
        $questions = $ctx->requetesVers($appareil['prefixe']);
        if ($questions > 0) {
            $fautes[] = $nom . ' : ' . $questions . ' appel(s) RPC vers un appareil qui dort.';
        }
        $modele = empty($ctx->modeles) ? null : $ctx->modeles[count($ctx->modeles) - 1];
        if ($modele === null) {
            $fautes[] = $nom . ' : aucun modèle — son NotifyFullStatus suffit pourtant à le décrire.';
            continue;
        }
        if ($modele->hasAvailability()) {
            $fautes[] = $nom . ' : une disponibilité est déclarée sur « '
                . $modele->availability()['topic'] . ' » — un appareil qui dort y est « false » '
                . 'presque tout le temps.';
        }
        if (!$modele->isBatteryPowered()) {
            $fautes[] = $nom . ' : meta.battery_powered est faux.';
        }
        $cles = mqttbeClesGen2($modele);
        if (in_array('online', $cles, true)) {
            $fautes[] = $nom . ' : un canal « online » a été créé malgré tout.';
        }
        $aBatterie = false;
        foreach ($cles as $cle) {
            if (substr($cle, -8) === '.battery') {
                $aBatterie = true;
            }
        }
        if (!$aBatterie) {
            $fautes[] = $nom . ' : aucune commande de niveau de batterie sur un appareil sur pile.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 7. Un volet est un volet
     *
     * Le piège de la génération 1 : un 2.5 en mode volet publiait encore
     * `relays[]` dans son info, et le lire sans regarder `rollers[]` donnait
     * deux interrupteurs qui ne commandaient rien. En génération 2, les
     * composants disparaissent réellement — ce contrôle vérifie qu'on lit bien
     * ce que l'appareil énumère, et qu'on n'a pas recopié la prudence de Gen1
     * en inventant un aiguillage.
     * ------------------------------------------------------------------- */
    $titre = 'profil volet : un volet, et pas un seul interrupteur';
    $fautes = array();
    if (isset($modeles['plus2pm_volet'])) {
        $cles = mqttbeClesGen2($modeles['plus2pm_volet']);
        foreach ($cles as $cle) {
            if (strpos($cle, 'switch.') === 0) {
                $fautes[] = 'un canal « ' . $cle . ' » a été créé sur un appareil en profil volet.';
            }
        }
        foreach (array('cover.0.state', 'cover.0.open', 'cover.0.close', 'cover.0.stop',
                       'cover.0.position') as $attendu) {
            if (!in_array($attendu, $cles, true)) {
                $fautes[] = 'canal « ' . $attendu . ' » manquant.';
            }
        }
        $canaux = mqttbeCanauxGen2($modeles['plus2pm_volet']);
        if (isset($canaux['cover.0.state'])) {
            $chemin = $canaux['cover.0.state']->sourceSelector();
            if (!isset($chemin['path']) || $chemin['path'] !== 'params.cover:0.current_pos') {
                $fautes[] = 'l\'état du volet ne lit pas la position : c\'est elle que le widget '
                    . 'montre, et « ouvre » ou « ferme » ne se range dans aucune capacité.';
            }
        }
        /* Le curseur ne doit exister que si l'appareil sait où il en est. */
        $ctxNonCalibre = new MqttbeContexteEssaiGen2();
        $volet = $appareils['plus2pm_volet'];
        foreach ($volet['composants'] as $rang => $composant) {
            if ($composant['key'] === 'cover:0') {
                $volet['composants'][$rang]['status']['pos_control'] = false;
                unset($volet['composants'][$rang]['status']['current_pos']);
            }
        }
        $modeleNonCalibre = mqttbeModeleGen2($volet, $ctxNonCalibre);
        if ($modeleNonCalibre !== null
            && in_array('cover.0.position', mqttbeClesGen2($modeleNonCalibre), true)) {
            $fautes[] = 'un volet non calibré reçoit quand même un curseur de position : il ne '
                . 'sait pas s\'y rendre, et le curseur ne fera rien, sans un mot pour l\'expliquer.';
        }
    } else {
        $fautes[] = 'la capture du 2PM en profil volet manque.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 8. LES ÉNERGIES SONT CONVERTIES
     *
     * Toutes les énergies Gen2 sont en watt-heures, sans exception. Une
     * commande qui annonce « kWh » sans diviser affiche 87759 là où il faut
     * lire 87,759 : trois ordres de grandeur, et un utilisateur qui en conclut
     * que le plugin est faux — ce en quoi il a raison.
     * ------------------------------------------------------------------- */
    $titre = 'énergies : watt-heures convertis, jamais étiquetés kWh tels quels';
    $fautes = array();
    $comptees = 0;
    foreach ($modeles as $nom => $modele) {
        foreach ($modele->channels() as $canal) {
            if ($canal->capability() !== 'energy.total' && $canal->capability() !== 'energy.returned') {
                continue;
            }
            $comptees++;
            $valeur = $canal->value();
            $echelle = isset($valeur['transform']['scale']) ? (float) $valeur['transform']['scale'] : 1.0;
            if ($canal->unit() !== 'kWh') {
                $fautes[] = $nom . '/' . $canal->key() . ' : unité « ' . $canal->unit() . ' ».';
            }
            if (abs($echelle - 0.001) > 1e-9) {
                $fautes[] = $nom . '/' . $canal->key() . ' : échelle ' . $echelle
                    . ' au lieu de 0,001 — la valeur affichée serait mille fois trop grande.';
            }
        }
    }
    if ($comptees === 0) {
        $fautes[] = 'aucune commande d\'énergie dans tout le parc : le contrôle ne prouve rien.';
    }
    /* Et la réinjection n'est pas de la consommation : étiquetée en
     * `energy.total`, elle apparaîtrait dans la vue Maison comme une dépense,
     * et le bilan d'une installation solaire serait exactement inversé. */
    if (isset($modeles['pro3em'])) {
        $canaux = mqttbeCanauxGen2($modeles['pro3em']);
        foreach ($canaux as $cle => $canal) {
            if (strpos($cle, 'ret') !== false && $canal->capability() === 'energy.total') {
                $fautes[] = 'pro3em/' . $cle . ' : la réinjection est comptée en consommation.';
            }
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 9. Le repli des micrologiciels anciens
     *
     * `Shelly.GetComponents` n'existe pas avant la version 1.x, et le refus
     * n'est pas une erreur de la liste commune : c'est un 404 « No handler
     * for … ». L'union des clés de `Shelly.GetStatus` et de `Shelly.GetConfig`
     * donne le même inventaire — sans lui, tout un pan du parc resterait
     * invisible.
     * ------------------------------------------------------------------- */
    $titre = 'micrologiciel ancien : le repli GetStatus + GetConfig découvre autant';
    $fautes = array();
    if (isset($modeles['mini1g3'])) {
        $cles = mqttbeClesGen2($modeles['mini1g3']);
        foreach (array('switch.0.state', 'switch.0.on', 'switch.0.off', 'switch.0.toggle',
                       'input.0.state', 'sys.uptime', 'online') as $attendu) {
            if (!in_array($attendu, $cles, true)) {
                $fautes[] = 'canal « ' . $attendu . ' » manquant après le repli.';
            }
        }
        /* La configuration du repli porte le type de l'entrée : sans elle, on
         * ne saurait pas si `input:0` est un bouton ou un interrupteur. */
        if ($modeles['mini1g3']->confidence() !== 'certain') {
            $fautes[] = 'confiance « ' . $modeles['mini1g3']->confidence() . ' » : une conversation '
                . 'menée à son terme, fût-ce par le repli, donne un inventaire complet.';
        }
        /* Un appareil sans wattmètre ne doit recevoir aucune commande de
         * puissance : c'est la faute que la génération 1 a payé pour apprendre. */
        foreach ($cles as $cle) {
            if (substr($cle, -6) === '.power' || substr($cle, -7) === '.energy') {
                $fautes[] = 'canal « ' . $cle . ' » sur un appareil sans wattmètre : il resterait '
                    . 'éternellement à zéro.';
            }
        }
    } else {
        $fautes[] = 'la capture du micrologiciel ancien manque.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 10. La pagination est respectée
     *
     * La taille d'une page est imposée par l'appareil — il n'y a ni `limit` ni
     * `count`. Supposer qu'un appel suffit revient à perdre en silence la
     * moitié des composants d'un appareil chargé.
     * ------------------------------------------------------------------- */
    $titre = 'Shelly.GetComponents : toutes les pages sont demandées';
    $fautes = array();
    if (isset($modeles['pro4pm'])) {
        $cles = mqttbeClesGen2($modeles['pro4pm']);
        /* La capture est servie en deux pages : les quatre sorties sont dans la
         * première, l'Ethernet et le système dans la seconde. Un adapter qui
         * s'arrêterait à la première page les perdrait. */
        foreach (array('switch.0.state', 'switch.3.state', 'sys.uptime') as $attendu) {
            if (!in_array($attendu, $cles, true)) {
                $fautes[] = 'canal « ' . $attendu . ' » manquant : la seconde page n\'a pas été lue.';
            }
        }
        if ($modeles['pro4pm']->ip() !== '192.0.2.32') {
            $fautes[] = 'l\'adresse IP vient de la seconde page (Ethernet) et vaut « '
                . $modeles['pro4pm']->ip() . ' ».';
        }
    } else {
        $fautes[] = 'la capture du Pro 4PM manque.';
    }
    /* Et la boucle doit s'arrêter, même si l'appareil ment sur son total. */
    $ctxMenteur = new MqttbeContexteEssaiGen2();
    $adapterMenteur = mqttbeAdapterGen2();
    $prefixeMenteur = 'shellymenteur-a8b0c1000099';
    $adapterMenteur->onTick($ctxMenteur);
    $adapterMenteur->onMessage($prefixeMenteur . '/online', 'true', true, $ctxMenteur);
    $adapterMenteur->onTick($ctxMenteur);
    mqttbeRepondGen2($adapterMenteur, $ctxMenteur, $prefixeMenteur, $prefixeMenteur,
        array('id' => $prefixeMenteur, 'mac' => 'A8B0C1000099', 'model' => 'INCONNU', 'gen' => 2));
    $adapterMenteur->onTick($ctxMenteur);
    for ($tour = 0; $tour < 40; $tour++) {
        /* Une page vide, mais un total qui prétend qu'il en reste. */
        mqttbeRepondGen2($adapterMenteur, $ctxMenteur, $prefixeMenteur, $prefixeMenteur,
            array('components' => array(), 'offset' => 0, 'total' => 999));
        $ctxMenteur->avance(1);
        $adapterMenteur->onTick($ctxMenteur);
    }
    if ($ctxMenteur->requetesVers($prefixeMenteur) > 20) {
        $fautes[] = 'un appareil qui annonce 999 composants et n\'en livre aucun fait tourner la '
            . 'pagination indéfiniment (' . $ctxMenteur->requetesVers($prefixeMenteur) . ' appels).';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 11. Les charges utiles d'action sont du JSON que l'appareil acceptera
     *
     * Une action Gen2 est un appel RPC écrit à la main, jetons de substitution
     * compris : `#slider#` n'est pas entre guillemets, parce que `pos` attend
     * un nombre. Une paire de guillemets en trop, et l'appareil refuse l'appel
     * — silencieusement, puisque personne ne lit sa réponse.
     * ------------------------------------------------------------------- */
    $titre = 'actions : charge utile RPC valide une fois les jetons substitués';
    $fautes = array();
    $actions = 0;
    foreach ($modeles as $nom => $modele) {
        foreach ($modele->channels() as $canal) {
            if (!$canal->hasSink()) {
                continue;
            }
            $actions++;
            $topic = $canal->sinkTopic();
            if (strpos($topic, '+') !== false || strpos($topic, '#') !== false) {
                $fautes[] = $nom . '/' . $canal->key() . ' : topic « ' . $topic . ' » — MQTT 3.1.1 '
                    . '§3.3.2 interdit les jokers à la publication, le broker fermerait la '
                    . 'connexion à chaque appui.';
            }
            /* Ce que Jeedom substitue réellement : un nombre pour un curseur et
             * pour chaque composante de couleur, une chaîne pour un message. */
            $charge = str_replace(
                array('#slider#', '#red#', '#green#', '#blue#', '#message#'),
                array('42', '255', '128', '0', 'bonjour'),
                $canal->sinkPayload());
            $decode = json_decode($charge, true);
            if (!is_array($decode)) {
                $fautes[] = $nom . '/' . $canal->key() . ' : charge utile illisible après '
                    . 'substitution — « ' . $charge . ' ».';
                continue;
            }
            foreach (array('id', 'src', 'method') as $requis) {
                if (!isset($decode[$requis])) {
                    $fautes[] = $nom . '/' . $canal->key() . ' : clé « ' . $requis . ' » absente '
                        . 'de l\'appel RPC.';
                }
            }
            if (isset($decode['src']) && preg_match('/[#+]/', (string) $decode['src'])) {
                $fautes[] = $nom . '/' . $canal->key() . ' : le « src » de l\'appel ne peut pas '
                    . 'composer un topic de réponse.';
            }
            if (substr($topic, -4) !== '/rpc') {
                $fautes[] = $nom . '/' . $canal->key() . ' : publie sur « ' . $topic . ' » et non '
                    . 'sur le canal RPC.';
            }
        }
    }
    if ($actions === 0) {
        $fautes[] = 'aucune action dans tout le parc : le contrôle ne prouve rien.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 12. Toute information se lit sur `events/rpc`
     *
     * `<P>/status/<composant>` serait plus commode, mais il est muet :
     * `status_ntf` vaut `false` en sortie d'usine, et le critère de ce jalon est
     * qu'un appareil soit découvert sans que son propriétaire ne touche à sa
     * configuration. Un canal bâti sur ce topic-là serait définitivement vide.
     * ------------------------------------------------------------------- */
    $titre = 'informations : lues sur events/rpc, jamais sur status/* qui est muet d\'usine';
    $fautes = array();
    foreach ($modeles as $nom => $modele) {
        foreach ($modele->channels() as $canal) {
            if (!$canal->hasSource()) {
                continue;
            }
            $topic = $canal->sourceTopic();
            if (strpos($topic, '/status/') !== false || substr($topic, -7) === '/status') {
                $fautes[] = $nom . '/' . $canal->key() . ' : lit « ' . $topic . ' », que l\'appareil '
                    . 'ne publie pas tant que status_ntf est faux — c\'est-à-dire d\'usine.';
            }
            if (strpos($topic, '+') !== false || strpos($topic, '#') !== false) {
                $fautes[] = $nom . '/' . $canal->key() . ' : topic de lecture « ' . $topic
                    . ' » contient un joker — il déverserait l\'état de tout le parc sur une commande.';
            }
            if (substr($topic, -11) === '/events/rpc') {
                $selecteur = $canal->sourceSelector();
                $chemin = isset($selecteur['path']) ? $selecteur['path'] : '';
                if (!isset($selecteur['type']) || $selecteur['type'] !== 'json') {
                    $fautes[] = $nom . '/' . $canal->key() . ' : sélecteur « '
                        . (isset($selecteur['type']) ? $selecteur['type'] : '?') . ' » — le démon '
                        . 'ne sait appliquer que « json ».';
                } elseif (strpos($chemin, 'params.') !== 0) {
                    $fautes[] = $nom . '/' . $canal->key() . ' : chemin « ' . $chemin . ' » — une '
                        . 'notification porte ses valeurs sous « params ».';
                }
            }
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 13. Un événement retenu ne déclenche rien
     *
     * Un état rejoué ne coûte rien : il dit ce qui est. Un événement rejoué
     * déclenche un scénario que personne n'a demandé, des semaines après le
     * geste qui l'a produit.
     * ------------------------------------------------------------------- */
    $titre = 'un NotifyEvent retenu est ignoré, un état retenu ne l\'est pas';
    $fautes = array();
    $prefixeE = 'shellyplus1-a8b0c1000040';
    $evenement = json_encode(array('src' => $prefixeE, 'dst' => $prefixeE . '/events',
        'method' => 'NotifyEvent', 'params' => array('ts' => 1789644518.0,
            'events' => array(array('component' => 'input:0', 'id' => 0,
                                    'event' => 'single_push', 'ts' => 1789644518.0)))));
    $etat = json_encode(array('src' => $prefixeE, 'dst' => $prefixeE . '/events',
        'method' => 'NotifyStatus', 'params' => array('ts' => 1789644518.0,
            'switch:0' => array('id' => 0, 'output' => true))));

    $ctxRetenu = new MqttbeContexteEssaiGen2();
    $adapterRetenu = mqttbeAdapterGen2();
    $adapterRetenu->onMessage($prefixeE . '/events/rpc', $evenement, true, $ctxRetenu);
    if ($ctxRetenu->requetesVers($prefixeE) > 0 || $ctxRetenu->memoryCount() > 2) {
        $fautes[] = 'un événement retenu a ouvert un dossier et provoqué une conversation : le '
            . 'drapeau « retained » dit « état rejoué », pas « cela vient de se produire ».';
    }
    $ctxVivant = new MqttbeContexteEssaiGen2();
    $adapterVivant = mqttbeAdapterGen2();
    $adapterVivant->onMessage($prefixeE . '/events/rpc', $etat, true, $ctxVivant);
    $adapterVivant->onTick($ctxVivant);
    if ($ctxVivant->requetesVers($prefixeE) === 0) {
        $fautes[] = 'un NotifyStatus retenu n\'a rien déclenché : c\'est pourtant ainsi qu\'un '
            . 'appareil se signale au démarrage du démon.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 14. Broker absent : rien n'est perdu, rien n'est affirmé à tort
     *
     * C'est l'état ordinaire d'un démon qui démarre en même temps que son
     * broker, après une coupure de courant. Une demande tenue pour faite alors
     * qu'elle n'est pas partie consomme les trois relances à vide, et le parc
     * reste invisible jusqu'au prochain redémarrage.
     * ------------------------------------------------------------------- */
    $titre = 'broker injoignable : les demandes sont reprises, le journal ne ment pas';
    $fautes = array();
    $ctxSansBroker = new MqttbeContexteEssaiGen2();
    $ctxSansBroker->broker = false;
    $adapterSansBroker = mqttbeAdapterGen2();
    for ($tour = 0; $tour < 5; $tour++) {
        $adapterSansBroker->onTick($ctxSansBroker);
        $ctxSansBroker->avance(30);
    }
    foreach ($ctxSansBroker->journal as $ligne) {
        if (strpos($ligne, 'info : ') === 0 && strpos($ligne, 'annonce demandée') !== false) {
            $fautes[] = 'le journal affirme « annonce demandée » alors que rien n\'est parti : '
                . 'c\'est la seule trace dont dispose celui qui cherche pourquoi il ne voit rien.';
            break;
        }
    }
    $ctxSansBroker->broker = true;
    $adapterSansBroker->onTick($ctxSansBroker);
    if ($ctxSansBroker->demandesAnnonce() === 0) {
        $fautes[] = 'le broker revenu, aucune demande n\'est repartie : les relances ont été '
            . 'consommées à vide.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 15. Idempotence, et relance
     *
     * Les notifications se succèdent sans fin sur un compteur d'énergie :
     * réémettre le modèle à chaque fois réécrirait la base plusieurs fois par
     * seconde. À l'inverse, « relancer la découverte » ne veut rien dire d'autre
     * que « redis-moi tout » — un équipement supprimé par erreur doit revenir.
     * ------------------------------------------------------------------- */
    $titre = 'un modèle inchangé n\'est pas réémis ; une relance le réémet';
    $fautes = array();
    $ctxIdem = new MqttbeContexteEssaiGen2();
    $adapterIdem = mqttbeAdapterGen2();
    mqttbeRejoueGen2($adapterIdem, $ctxIdem, $appareils['plus1pm']);
    $apresPremier = count($ctxIdem->modeles);
    if ($apresPremier !== 1) {
        $fautes[] = 'la première découverte a émis ' . $apresPremier . ' modèles au lieu d\'un.';
    }
    /* Le même état, republié : le RSSI et la puissance changent à chaque trame,
     * mais ils ne font pas partie de l'empreinte. */
    for ($tour = 0; $tour < 5; $tour++) {
        $adapterIdem->onMessage($appareils['plus1pm']['prefixe'] . '/events/rpc',
            json_encode(array('src' => $appareils['plus1pm']['deviceinfo']['id'],
                'method' => 'NotifyStatus', 'params' => array('ts' => 1789644518.0,
                    'switch:0' => array('id' => 0, 'apower' => 1284.3 + $tour),
                    'wifi' => array('rssi' => -52 - $tour)))), false, $ctxIdem);
        $ctxIdem->avance(1);
        $adapterIdem->onTick($ctxIdem);
    }
    if (count($ctxIdem->modeles) !== $apresPremier) {
        $fautes[] = 'la télémétrie a fait réémettre le modèle '
            . (count($ctxIdem->modeles) - $apresPremier) . ' fois : la puissance et le signal '
            . 'changent à chaque trame, et la base serait réécrite en boucle.';
    }
    $ctxIdem->relance = true;
    $adapterIdem->onTick($ctxIdem);
    mqttbeRejoueGen2($adapterIdem, $ctxIdem, $appareils['plus1pm']);
    if (count($ctxIdem->modeles) <= $apresPremier) {
        $fautes[] = 'après une relance demandée, le modèle n\'a pas été réémis : un équipement '
            . 'supprimé par erreur dans Jeedom ne reviendrait jamais.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 16. La génération 1 n'est pas de notre ressort, et réciproquement
     *
     * `shellies/announce` est un topic FIXE, que les deux générations
     * alimentent. Sans arbitrage, le même appareil serait décrit deux fois — et
     * l'un des deux modèles porterait des topics que l'appareil ne publie pas.
     * ------------------------------------------------------------------- */
    $titre = 'une annonce Gen1 n\'est pas reprise, et « shellies/… » n\'est pas un préfixe Gen2';
    $fautes = array();
    $ctxGen1 = new MqttbeContexteEssaiGen2();
    $adapterGen1 = mqttbeAdapterGen2();
    /* Une annonce de génération 1 : ni champ « gen », ni autre chose qui le
     * laisse deviner. */
    $adapterGen1->onMessage('shellies/announce', json_encode(array(
        'id' => 'shelly1-a8b0c1000001', 'model' => 'SHSW-1', 'mac' => 'A8B0C1000001',
        'ip' => '192.0.2.11', 'new_fw' => false, 'fw_ver' => '20230913-112003/v1.14.0')),
        true, $ctxGen1);
    /* Et le `online` d'un appareil de la génération 1, que notre filtre
     * `+/+/online` attrape forcément. */
    $adapterGen1->onMessage('shellies/shelly1-a8b0c1000001/online', 'true', true, $ctxGen1);
    for ($tour = 0; $tour < 5; $tour++) {
        $ctxGen1->avance(10);
        $adapterGen1->onTick($ctxGen1);
    }
    if (!empty($ctxGen1->modeles)) {
        $fautes[] = 'un appareil de la génération 1 a donné ' . count($ctxGen1->modeles)
            . ' modèle(s) ici : il serait décrit deux fois, et l\'un des deux serait muet.';
    }
    foreach ($ctxGen1->publications as $publication) {
        if (strpos($publication['topic'], 'shellies/') === 0
            && $publication['topic'] !== 'shellies/command') {
            $fautes[] = 'un appel a été publié sur « ' . $publication['topic'] . ' » : personne '
                . 'n\'y écoute, et vingt-deux Gen1 feraient soixante-six questions sans réponse.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 17. Préfixe personnalisé et composants virtuels
     *
     * La documentation ne compte pas le « / » parmi les caractères interdits
     * d'un préfixe : « maison/salon/prise » est légal, et se rencontre. Un
     * adapter qui compterait les niveaux de topic pour retrouver le préfixe le
     * manquerait entièrement.
     *
     * Les composants virtuels, eux, n'existent pas dans le matériel : leur
     * propriétaire les a créés, souvent pour qu'un script y publie une valeur.
     * `meta.ui.view` valant « label » veut dire AFFICHAGE SEUL, et lui créer une
     * action donnerait un bouton qui ne commande rien.
     * ------------------------------------------------------------------- */
    $titre = 'préfixe à barres obliques et composants virtuels';
    $fautes = array();
    if (isset($modeles['prefixe_personnalise'])) {
        $modele = $modeles['prefixe_personnalise'];
        $canaux = mqttbeCanauxGen2($modele);
        foreach ($modele->channels() as $canal) {
            $topic = $canal->hasSource() ? $canal->sourceTopic() : $canal->sinkTopic();
            if (strpos($topic, 'maison/salon/prise/') !== 0) {
                $fautes[] = $canal->key() . ' : topic « ' . $topic . ' » — le préfixe à trois '
                    . 'niveaux n\'a pas été retrouvé entier.';
            }
        }
        foreach (array('boolean.200.value', 'boolean.200.on', 'boolean.200.off',
                       'number.201.value', 'number.201.set',
                       'text.202.value', 'text.202.set', 'enum.203.value') as $attendu) {
            if (!isset($canaux[$attendu])) {
                $fautes[] = 'canal virtuel « ' . $attendu . ' » manquant.';
            }
        }
        /* `enum:203` est en « label » : lecture seule. */
        if (isset($canaux['enum.203.set'])) {
            $fautes[] = 'une action a été créée pour un composant virtuel en affichage seul.';
        }
        if (isset($canaux['number.201.value']) && $canaux['number.201.value']->unit() !== '°C') {
            $fautes[] = 'l\'unité d\'un nombre virtuel vient de meta.ui.unit et vaut « '
                . $canaux['number.201.value']->unit() .' ».';
        }
    } else {
        $fautes[] = 'la capture au préfixe personnalisé manque.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 18. Les noms : uniques, et jamais redondants
     *
     * cmd (eqLogic_id, name) est unique : deux commandes homonymes font échouer
     * l'enregistrement de la seconde. Et une commande « Position Volet salon »
     * sur un équipement déjà nommé « … — Volet salon » répète ce que
     * l'utilisateur vient de lire : c'est la faute que Home Assistant a livrée
     * puis fermée sans correctif.
     * ------------------------------------------------------------------- */
    $titre = 'noms de commandes : uniques, et sans répéter le nom de l\'équipement';
    $fautes = array();
    foreach ($modeles as $nom => $modele) {
        $vus = array();
        foreach ($modele->channels() as $canal) {
            $libelle = $canal->name();
            if ($libelle === '') {
                $fautes[] = $nom . '/' . $canal->key() . ' : commande sans nom.';
                continue;
            }
            if (isset($vus[$libelle])) {
                $fautes[] = $nom . ' : deux commandes nommées « ' . $libelle . ' » (' . $vus[$libelle]
                    . ' et ' . $canal->key() . ') — la seconde ferait échouer l\'enregistrement.';
            }
            $vus[$libelle] = $canal->key();
            $usage = $modele->deviceName();
            if ($usage !== '' && $libelle !== $usage && strpos($libelle, $usage) !== false) {
                $fautes[] = $nom . '/' . $canal->key() . ' : « ' . $libelle . ' » répète le nom de '
                    . 'l\'équipement, qui le porte déjà.';
            }
        }
        if ($modele->name() === '') {
            $fautes[] = $nom . ' : équipement sans nom technique.';
        }
    }
    /* Et sur un appareil à plusieurs sorties, l'étiquette doit revenir : quatre
     * commandes « État » sur un Pro 4PM ne s'enregistreraient pas. */
    if (isset($modeles['pro4pm'])) {
        $canaux = mqttbeCanauxGen2($modeles['pro4pm']);
        if (isset($canaux['switch.0.state'], $canaux['switch.1.state'])
            && $canaux['switch.0.state']->name() === $canaux['switch.1.state']->name()) {
            $fautes[] = 'pro4pm : les quatre sorties portent le même nom.';
        }
        if (isset($canaux['switch.0.state'])
            && strpos($canaux['switch.0.state']->name(), 'Prise bureau') === false) {
            $fautes[] = 'pro4pm : le nom donné à la sortie dans l\'application Shelly n\'est pas '
                . 'repris (« ' . $canaux['switch.0.state']->name() . ' »).';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 19. Toutes les capacités employées existent
     *
     * Une capacité inconnue ne fait pas échouer la création : la fabrique se
     * rabat en silence sur `generic.value`, et la commande perd son type, son
     * unité et son widget. Le symptôme est une température qui s'affiche en
     * texte, et rien dans le journal pour dire pourquoi.
     * ------------------------------------------------------------------- */
    $titre = 'capacités : toutes connues du vocabulaire';
    if (!$vocabulaireCharge) {
        $resultats[] = mqttbeIndecis($titre, 'core/config/capabilities.json n\'a pas pu être lu : '
            . 'validate() ne peut rien affirmer sur les capacités.');
    } else {
        $fautes = array();
        foreach ($modeles as $nom => $modele) {
            foreach ($modele->validate() as $refus) {
                $fautes[] = $nom . ' : ' . $refus;
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));
    }

    /* ---------------------------------------------------------------------
     * 20. L'adapter ne parle qu'au broker
     *
     * Tout passe par MQTT, et c'est le principe même de ce plugin. Une requête
     * HTTP partirait d'ailleurs de la boucle principale du démon, celle qui lit
     * la socket du broker : quelques appareils éteints, et ce sont autant de
     * délais d'expiration pendant lesquels plus rien n'est routé.
     * ------------------------------------------------------------------- */
    $titre = 'aucune requête réseau : tout passe par le broker';
    $fautes = array();
    $source = file_get_contents(mqttbeCheminAdapterGen2());
    foreach (mqttbeIdentifiants($source) as $identifiant) {
        if (preg_match('/^(curl_|fsockopen|stream_socket_client|file_get_contents|fopen|http_|socket_)/i',
                       $identifiant)) {
            /* file_get_contents sert au catalogue, un fichier local du plugin :
             * c'est le seul emploi admis, et il est vérifié juste après. */
            if ($identifiant === 'file_get_contents' || $identifiant === 'fopen') {
                continue;
            }
            $fautes[] = 'l\'adapter appelle ' . $identifiant . '().';
        }
    }
    if (preg_match('#file_get_contents\s*\(\s*(?!\$this->cheminCatalogue)#', $source)) {
        $fautes[] = 'file_get_contents() est appelé sur autre chose que le catalogue local.';
    }
    if (preg_match('#https?://#', str_replace(array('https://shelly-api-docs.shelly.cloud',
            'https://www.gnu.org', 'http://\' . $ip', 'http://' . '\' . $ip'), '', $source))) {
        /* Les seules adresses admises sont celles des commentaires de source et
         * l'URL de configuration de l'appareil, qui n'est pas appelée mais
         * affichée : c'est un lien sur lequel l'utilisateur clique. */
        $restes = array();
        foreach (mqttbeChaines($source) as $chaine) {
            if (preg_match('#^https?://#', $chaine) && strpos($chaine, '://\' . $ip') === false) {
                $restes[] = $chaine;
            }
        }
        foreach ($restes as $reste) {
            if (strpos($reste, 'http://') === 0 && strpos($reste, 'shelly-api-docs') === false) {
                $fautes[] = 'adresse en dur dans le code : « ' . $reste . ' ».';
            }
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 21. Les captures sont anonymisées
     *
     * Le dépôt part sur GitHub. Une capture brute y publierait le réseau d'une
     * maison : son SSID, ses adresses, les identifiants de ses appareils.
     * ------------------------------------------------------------------- */
    $titre = 'captures anonymisées';
    $fautes = array();
    $brut = file_get_contents(mqttbeRacine() . '/tests/fixtures/shelly/gen2/conversations.json');
    if (preg_match_all('/\b(?:10|127)\.\d{1,3}\.\d{1,3}\.\d{1,3}\b|\b192\.168\.\d{1,3}\.\d{1,3}\b'
        . '|\b172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}\b/', $brut, $trouves)) {
        $fautes[] = 'adresse d\'un réseau réel : ' . implode(', ', array_unique($trouves[0]))
            . ' — les captures s\'écrivent en 192.0.2.x, réservé à la documentation.';
    }
    if (preg_match_all('/"ssid"\s*:\s*"([^"]+)"/', $brut, $ssids)) {
        foreach (array_unique($ssids[1]) as $ssid) {
            if ($ssid !== 'reseau-essai') {
                $fautes[] = 'SSID « ' . $ssid . ' » : le nom d\'un vrai réseau.';
            }
        }
    }
    if (preg_match_all('/"mac"\s*:\s*"([0-9A-Fa-f]{12})"/', $brut, $macs)) {
        foreach (array_unique($macs[1]) as $mac) {
            if (strtoupper(substr($mac, 0, 6)) !== 'A8B0C1') {
                $fautes[] = 'MAC « ' . $mac . ' » : hors de la plage fabriquée A8B0C1xxxxxx.';
            }
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 22. La mémoire tient sur un grand parc
     *
     * Le moteur borne chaque adapter à 512 clés et REFUSE les suivantes, sans
     * rien évincer. Un adapter qui mémorise par topic vu plutôt que par appareil
     * passe tous les contrôles ci-dessus et fait déborder la mémoire en
     * production, sur un parc dont personne n'avait prévu la taille.
     * ------------------------------------------------------------------- */
    $titre = 'mémoire : bornée par appareil, et non par message reçu';
    $fautes = array();
    $ctxParc = new MqttbeContexteEssaiGen2();
    $adapterParc = mqttbeAdapterGen2();
    $adapterParc->onTick($ctxParc);
    for ($numero = 0; $numero < 120; $numero++) {
        $prefixe = sprintf('shellyplus1-a8b0c10001%02d', $numero);
        $adapterParc->onMessage($prefixe . '/online', 'true', true, $ctxParc);
        /* Et de la télémétrie, beaucoup : c'est elle qui ferait exploser une
         * mémoire tenue par message. */
        for ($trame = 0; $trame < 5; $trame++) {
            $adapterParc->onMessage($prefixe . '/events/rpc', json_encode(array(
                'src' => $prefixe, 'method' => 'NotifyStatus',
                'params' => array('ts' => 1789644518.0,
                    'switch:0' => array('id' => 0, 'apower' => 10.0 + $trame)))), false, $ctxParc);
        }
    }
    $ctxParc->avance(1);
    $adapterParc->onTick($ctxParc);
    if ($ctxParc->refus > 0) {
        $fautes[] = $ctxParc->refus . ' écriture(s) refusée(s) sur un parc de 120 appareils : le '
            . 'plafond du moteur est atteint bien avant la taille d\'un parc réaliste.';
    }
    /* Quatre clés fixes, plus une par appareil : c'est la seule forme qui tienne. */
    if ($ctxParc->memoryCount() > 130) {
        $fautes[] = $ctxParc->memoryCount() . ' clés pour 120 appareils : la mémoire n\'est pas '
            . 'tenue par appareil.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Découverte Shelly Gen2+', mqttbeControlesShellyGen2());
}
