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

/*
 * La charge utile d'une action, telle que Jeedom la publiera.
 *
 * Elle rejoue mqttbeCmd::execute() — et elle doit continuer de le faire : un
 * contrôle qui substituerait autrement que le cœur validerait une charge utile
 * que personne n'enverra jamais. Le curseur passe donc par l'échelle et
 * l'arrondi que le canal déclare, et le texte est échappé comme json_encode le
 * fait.
 */
function mqttbeChargeGen2($_canal, $_curseur = 100, $_message = 'sal"ut\\ à' . "\n" . 'tous') {
    $valeurs = $_canal->value();
    $reglage = (isset($valeurs['slider']) && is_array($valeurs['slider'])) ? $valeurs['slider'] : array();
    $curseur = $_curseur;
    if (isset($reglage['scale']) && is_numeric($reglage['scale'])) {
        $decimales = isset($reglage['round']) ? (int) $reglage['round'] : 0;
        $nombre = round($_curseur * (float) $reglage['scale'], $decimales);
        $curseur = (floor($nombre) == $nombre) ? (string) (int) $nombre : (string) $nombre;
    }
    return str_replace(
        array('#slider#', '#red#', '#green#', '#blue#', '#message_json#', '#message#'),
        array((string) $curseur, '255', '128', '0',
              json_encode((string) $_message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
              'bonjour'),
        $_canal->sinkPayload());
}

function mqttbeCommandesGen2($_ctx, $_prefixe) {
    $vues = array();
    foreach ($_ctx->publications as $publication) {
        if ($publication['topic'] === $_prefixe . '/command') {
            $vues[] = $publication['payload'];
        }
    }
    return $vues;
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
             * pour chaque composante de couleur, une chaîne pour un message —
             * et un message qui porte un guillemet, une barre oblique inverse et
             * un retour à la ligne, parce que c'est là que la charge utile se
             * brise, et nulle part ailleurs. */
            $charge = mqttbeChargeGen2($canal, 42);
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
    /*
     * Et la protection ne vaut pas que pendant la découverte : les deux
     * commandes d'événement disent au démon d'ignorer ce que le broker rejoue.
     * Sans ce drapeau, l'adapter se garderait d'un événement retenu le temps de
     * découvrir l'appareil, et le routage le servirait à Jeedom une fois
     * l'équipement créé — c'est-à-dire là où un scénario part.
     */
    foreach ($modeles as $nomModele => $modeleEvenements) {
        foreach (mqttbeCanauxGen2($modeleEvenements) as $cleCanal => $canalEvenement) {
            if (strpos($cleCanal, 'event.') !== 0) {
                continue;
            }
            $valeursEvenement = $canalEvenement->value();
            $repetition = isset($valeursEvenement['repeat']) ? $valeursEvenement['repeat'] : array();
            if (empty($repetition['ignore_retained'])) {
                $fautes[] = $nomModele . '/' . $cleCanal . ' : la commande accepterait un '
                    . 'événement rejoué par le broker, et déclencherait au démarrage du démon un '
                    . 'scénario que personne n\'a demandé.';
            }
        }
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
    /* Les deux jeux de données, et surtout la capture réelle : c'est elle qui
     * sort d'une vraie maison, et c'est donc elle qui publierait un vrai réseau
     * si l'anonymisation avait manqué quelque chose. */
    $brut = '';
    foreach (array('conversations.json', 'capture-reelle.json') as $fichier) {
        $chemin = mqttbeRacine() . '/tests/fixtures/shelly/gen2/' . $fichier;
        if (is_readable($chemin)) {
            $brut .= file_get_contents($chemin);
        }
    }
    if (preg_match_all('/\b(?:10|127)\.\d{1,3}\.\d{1,3}\.\d{1,3}\b|\b192\.168\.\d{1,3}\.\d{1,3}\b'
        . '|\b172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}\b/', $brut, $trouves)) {
        $fautes[] = 'adresse d\'un réseau réel : ' . implode(', ', array_unique($trouves[0]))
            . ' — les captures s\'écrivent en 192.0.2.x, réservé à la documentation.';
    }
    if (preg_match_all('/"ssid"\s*:\s*"([^"]+)"/', $brut, $ssids)) {
        foreach (array_unique($ssids[1]) as $ssid) {
            /* Un seul nom fabriqué, et ses variantes : le point d'accès d'un
             * Shelly porte son propre SSID, distinct de celui du réseau auquel
             * il se connecte. La règle reste sans exception — tout ce qui ne
             * commence pas par « reseau-essai » est le nom d'un vrai réseau. */
            if (strpos($ssid, 'reseau-essai') !== 0) {
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

    /* ---------------------------------------------------------------------
     * 23. Un testament retenu ne fait parler personne
     *
     * `<P>/online` à `false` n'est pas publié par l'appareil : c'est son
     * testament, publié par le broker, et il est RETENU. Un appareil débranché
     * depuis six mois le rejoue donc à chaque démarrage du démon. L'interroger
     * coûte trois appels, trois expirations et un avertissement, pour un
     * appareil dont le broker vient de dire qu'il n'est plus là.
     * ------------------------------------------------------------------- */
    $titre = 'un « online » à false ne déclenche aucune question, et son retour la relance';
    $fautes = array();
    $ctxMort = new MqttbeContexteEssaiGen2();
    $adapterMort = mqttbeAdapterGen2();
    $prefixeMort = 'shellyplus1pm-a8b0c1000040';
    $adapterMort->onTick($ctxMort);
    $adapterMort->onMessage($prefixeMort . '/online', 'false', true, $ctxMort);
    for ($tour = 0; $tour < 4; $tour++) {
        $ctxMort->avance(6);
        $adapterMort->onTick($ctxMort);
    }
    if ($ctxMort->requetesVers($prefixeMort) !== 0) {
        $fautes[] = $ctxMort->requetesVers($prefixeMort) . ' appel(s) RPC à un appareil que le '
            . 'broker déclare hors ligne — sur un parc où quelques appareils sont morts, c\'est '
            . 'autant de questions sans réponse à chaque démarrage du démon.';
    }
    $adapterMort->onMessage($prefixeMort . '/online', 'true', true, $ctxMort);
    $ctxMort->avance(1);
    $adapterMort->onTick($ctxMort);
    if ($ctxMort->requetesVers($prefixeMort) === 0) {
        $fautes[] = 'l\'appareil est revenu et personne ne lui parle : une suspension qui ne se '
            . 'lève pas est un appareil perdu pour toujours.';
    }
    /* Et la conversation en cours s'arrête s'il repart. */
    $adapterMort->onMessage($prefixeMort . '/online', 'false', true, $ctxMort);
    $gele = $ctxMort->requetesVers($prefixeMort);
    for ($tour = 0; $tour < 4; $tour++) {
        $ctxMort->avance(6);
        $adapterMort->onTick($ctxMort);
    }
    if ($ctxMort->requetesVers($prefixeMort) !== $gele) {
        $fautes[] = 'la conversation continue après le testament : les trois tentatives se '
            . 'consomment pendant que l\'appareil est absent, et il n\'en restera aucune à son retour.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 24. La seconde porte : « MQTT control »
     *
     * Des Gen2 sur secteur restent muets au RPC, et un appareil dont
     * `enable_rpc` a été coupé ne répondra jamais. La documentation décrit une
     * autre voie, active d'usine (`enable_control` vaut `true`) et qui ne
     * dépend pas du RPC : `announce` et `status_update` sur `<P>/command`.
     * Elle n'est frappée qu'après le silence, et rien n'en dépend.
     * ------------------------------------------------------------------- */
    $titre = 'RPC muet : on frappe à la seconde porte, et le statut suffit';
    $fautes = array();
    $ctxMuet = new MqttbeContexteEssaiGen2();
    $adapterMuet = mqttbeAdapterGen2();
    $prefixeMuet = 'shellyplus2pm-a8b0c1000041';
    $adapterMuet->onTick($ctxMuet);
    $adapterMuet->onMessage($prefixeMuet . '/online', 'true', true, $ctxMuet);
    for ($tour = 0; $tour < 6; $tour++) {
        $ctxMuet->avance(6);
        $adapterMuet->onTick($ctxMuet);
    }
    $commandes = mqttbeCommandesGen2($ctxMuet, $prefixeMuet);
    foreach (array('announce', 'status_update') as $attendue) {
        if (!in_array($attendue, $commandes, true)) {
            $fautes[] = '« ' . $attendue . ' » n\'a jamais été demandé sur ' . $prefixeMuet
                . '/command : l\'appareil muet au RPC reste muet, alors qu\'une autre porte '
                . 'était ouverte.';
        }
    }
    if ($ctxMuet->requetesVers($prefixeMuet) > 3) {
        $fautes[] = $ctxMuet->requetesVers($prefixeMuet) . ' appels RPC à un appareil qui ne '
            . 'répond pas : trois tentatives, puis on essaie autre chose.';
    }
    /* Il répond, mais par la seconde porte. */
    $adapterMuet->onMessage($prefixeMuet . '/status', json_encode(array(
        'switch:0' => array('id' => 0, 'output' => true, 'apower' => 12.5),
        'sys'      => array('mac' => 'A8B0C1000041', 'uptime' => 4212, 'ram_free' => 150000),
        'wifi'     => array('sta_ip' => '192.0.2.41', 'status' => 'got ip', 'rssi' => -55),
    )), false, $ctxMuet);
    if (empty($ctxMuet->modeles)) {
        $fautes[] = 'aucun équipement après un statut complet reçu sur ' . $prefixeMuet
            . '/status : la réponse à status_update n\'est pas exploitée.';
    } else {
        $modeleMuet = $ctxMuet->modeles[count($ctxMuet->modeles) - 1];
        $clesMuet = mqttbeClesGen2($modeleMuet);
        foreach (array('switch.0.state', 'switch.0.power', 'sys.uptime') as $attendu) {
            if (!in_array($attendu, $clesMuet, true)) {
                $fautes[] = 'commande « ' . $attendu . ' » absente du modèle bâti sur le statut '
                    . 'diffusé (obtenues : ' . implode(', ', $clesMuet) . ').';
            }
        }
        if ($modeleMuet->confidence() !== 'certain') {
            $fautes[] = 'confiance « ' . $modeleMuet->confidence() . ' » alors que l\'appareil a '
                . 'livré son statut entier : un inventaire complet est un inventaire complet, '
                . 'quelle que soit la porte par laquelle il est arrivé.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 25. Un curseur publie une valeur que l'appareil accepte
     *
     * C'est le défaut le plus coûteux de tous, parce qu'il ne se voit ni à la
     * relecture, ni dans un journal : la commande existe, elle s'actionne, et
     * il ne se passe rien — ou pas ce qu'on demande.
     *
     *   la voie blanche se règle de 0 à 255, le curseur va de 0 à 100 : sans
     *     conversion, il plafonne à 39 % de la puissance ;
     *   la température de couleur se règle en KELVINS : un curseur 0-100
     *     n'envoie que des valeurs hors plage, toutes refusées ;
     *   un nombre virtuel a les bornes que son propriétaire lui a données.
     * ------------------------------------------------------------------- */
    $titre = 'curseurs : la valeur publiée est dans l\'échelle de l\'appareil';
    $fautes = array();

    $modeleBulbe = mqttbeModeleGen2($appareils['duobulb']);
    if ($modeleBulbe === null) {
        $fautes[] = 'la lampe blanc réglable n\'a produit aucun modèle.';
    } else {
        $canauxBulbe = mqttbeCanauxGen2($modeleBulbe);
        if (!isset($canauxBulbe['cct.0.color_temp'])) {
            $fautes[] = 'aucune commande de température de couleur sur une lampe qui en a une.';
        } else {
            $valeurs = $canauxBulbe['cct.0.color_temp']->value();
            $bornes = isset($valeurs['slider']) ? $valeurs['slider'] : array();
            if (!isset($bornes['min']) || !isset($bornes['max'])) {
                $fautes[] = 'la température de couleur n\'a pas de bornes : le curseur restera au '
                    . '0-100 du cœur, et l\'appareil refusera chacune de ses positions.';
            } elseif ((int) $bornes['min'] !== 2700 || (int) $bornes['max'] !== 6500) {
                $fautes[] = 'bornes ' . $bornes['min'] . '-' . $bornes['max'] . ' K au lieu du '
                    . '« ct_range » que l\'appareil annonce (2700-6500).';
            }
        }
    }

    $modeleRgbw = mqttbeModeleGen2($appareils['rgbw']);
    $canauxRgbw = ($modeleRgbw === null) ? array() : mqttbeCanauxGen2($modeleRgbw);
    if (!isset($canauxRgbw['rgbw.0.white'])) {
        $fautes[] = 'aucune commande de voie blanche sur un bandeau qui en publie une.';
    } else {
        /* Les deux extrémités sont exactes, et le milieu est à l'unité près :
         * 2,55 ne s'écrit pas en binaire, et 50 × 2,55 vaut 127,4999… Un
         * demi-point sur 255 ne se voit pas ; le plafond à 39 %, si. */
        foreach (array(100 => array(255, 255), 0 => array(0, 0), 50 => array(127, 128))
                 as $curseur => $plage) {
            $charge = json_decode(mqttbeChargeGen2($canauxRgbw['rgbw.0.white'], $curseur), true);
            $blanc = isset($charge['params']['white']) ? $charge['params']['white'] : null;
            if (!is_int($blanc) || $blanc < $plage[0] || $blanc > $plage[1]) {
                $fautes[] = 'curseur à ' . $curseur . ' % : la charge utile porte « '
                    . var_export($blanc, true) . ' » au lieu de ' . $plage[0] . ' — la voie blanche '
                    . 'se compte de 0 à 255.';
            }
        }
    }

    $modelePerso = mqttbeModeleGen2($appareils['prefixe_personnalise']);
    $canauxPerso = ($modelePerso === null) ? array() : mqttbeCanauxGen2($modelePerso);
    $configNombre = array();
    foreach ($appareils['prefixe_personnalise']['composants'] as $composant) {
        if ($composant['key'] === 'number:201') {
            $configNombre = $composant['config'];
        }
    }
    if (!isset($canauxPerso['number.201.set'])) {
        $fautes[] = 'aucun réglage pour le nombre virtuel 201.';
    } elseif (isset($configNombre['min'], $configNombre['max'])) {
        $valeurs = $canauxPerso['number.201.set']->value();
        $bornes = isset($valeurs['slider']) ? $valeurs['slider'] : array();
        if (!isset($bornes['min']) || !isset($bornes['max'])
            || (float) $bornes['min'] !== (float) $configNombre['min']
            || (float) $bornes['max'] !== (float) $configNombre['max']) {
            $fautes[] = 'le nombre virtuel est réglé de ' . $configNombre['min'] . ' à '
                . $configNombre['max'] . ' dans l\'appareil, et son curseur ne le sait pas.';
        }
    }
    /* Le bouton virtuel : aucun état, rien qu'une écriture — et c'est bien pour
     * cela qu'il existe. */
    if (!isset($canauxPerso['button.204.single_push'])) {
        $fautes[] = 'le bouton virtuel n\'a produit aucune commande : son statut est vide, et une '
            . 'garde « pas de valeur, pas de commande » l\'écarte au lieu de l\'actionner.';
    } else {
        $charge = json_decode(mqttbeChargeGen2($canauxPerso['button.204.single_push']), true);
        if (!isset($charge['params']['event']) || $charge['params']['event'] !== 'single_push') {
            $fautes[] = 'Button.Trigger sans « event » : le paramètre est obligatoire, et il ne '
                . 's\'appelle pas « event_type », qui est celui d\'Input.Trigger.';
        }
    }
    /* Et un texte qui porte un guillemet reste du JSON. */
    if (isset($canauxPerso['text.202.set'])) {
        $charge = json_decode(mqttbeChargeGen2($canauxPerso['text.202.set'], 0, 'a "b" \\ c'), true);
        if (!is_array($charge) || !isset($charge['params']['value'])
            || $charge['params']['value'] !== 'a "b" \\ c') {
            $fautes[] = 'un texte contenant un guillemet ou une barre oblique inverse brise la '
                . 'charge utile : l\'appareil la rejette sans un mot.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 26. Une configuration qui change relance l'inventaire
     *
     * Renommer une sortie dans l'application Shelly, changer le profil d'un
     * 2PM, régler une entrée en bouton, ajouter un composant virtuel : tout
     * cela change les commandes qu'il faudrait créer, et l'appareil le dit —
     * par l'événement `config_changed`, et par sa révision de configuration.
     * Sans cette relecture, un équipement reste figé sur l'inventaire du jour
     * de sa découverte, et personne ne le sait.
     * ------------------------------------------------------------------- */
    $titre = 'une configuration qui change relance l\'inventaire, et lui seul';
    $fautes = array();
    $ctxChange = new MqttbeContexteEssaiGen2();
    $adapterChange = mqttbeAdapterGen2();
    mqttbeRejoueGen2($adapterChange, $ctxChange, $appareils['plus1pm']);
    $prefixeChange = $appareils['plus1pm']['prefixe'];
    $trameChange = json_encode(array(
        'src' => $appareils['plus1pm']['deviceinfo']['id'],
        'dst' => $prefixeChange . '/events',
        'method' => 'NotifyEvent',
        'params' => array('ts' => 1789644600.0, 'events' => array(
            array('component' => 'switch:0', 'id' => 0, 'event' => 'config_changed',
                  'restart_required' => false, 'cfg_rev' => 27, 'ts' => 1789644600.0))),
    ));
    $avantChange = $ctxChange->requetesVers($prefixeChange);

    /* Retenu, l'événement dit un changement passé : le rejouer au démarrage du
     * démon ferait réinterroger tout le parc sans raison. */
    $adapterChange->onMessage($prefixeChange . '/events/rpc', $trameChange, true, $ctxChange);
    $ctxChange->avance(1);
    $adapterChange->onTick($ctxChange);
    if ($ctxChange->requetesVers($prefixeChange) !== $avantChange) {
        $fautes[] = 'un « config_changed » RETENU relance la découverte : au démarrage du démon, '
            . 'tout le parc serait réinterrogé sur des changements vieux de plusieurs semaines.';
    }

    $adapterChange->onMessage($prefixeChange . '/events/rpc', $trameChange, false, $ctxChange);
    $ctxChange->avance(1);
    $adapterChange->onTick($ctxChange);
    $requeteChange = $ctxChange->derniereRequete($prefixeChange . '/rpc');
    /* Le compte, et pas seulement la dernière requête : la découverte vient de
     * se terminer sur un `Shelly.GetComponents`, et lire la dernière ligne du
     * journal des publications dirait « oui » même si rien n'était reparti. */
    if ($ctxChange->requetesVers($prefixeChange) <= $avantChange) {
        $fautes[] = 'aucune question n\'est repartie après « config_changed » : l\'équipement '
            . 'gardera les commandes d\'avant jusqu\'à une relance faite à la main.';
    } elseif ($requeteChange === null || $requeteChange['method'] !== 'Shelly.GetComponents') {
        $fautes[] = 'après « config_changed », l\'appareil n\'est pas réinterrogé : son équipement '
            . 'gardera les commandes d\'avant jusqu\'à une relance faite à la main.';
    } elseif (!isset($requeteChange['params']['offset']) || (int) $requeteChange['params']['offset'] !== 0) {
        $fautes[] = 'l\'inventaire est redemandé au milieu de la pagination, et non depuis le début.';
    }
    /* Et l'équipement n'est pas vidé entre la demande et la réponse : tant que
     * l'appareil n'a pas reparlé, il garde tout ce qu'on savait de lui. */
    $dernierChange = empty($ctxChange->modeles) ? null : $ctxChange->modeles[count($ctxChange->modeles) - 1];
    if ($dernierChange !== null && $dernierChange->countChannels() < 5) {
        $fautes[] = 'l\'équipement a été vidé en attendant une réponse qui n\'est pas encore venue.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 27. Un champ disparu emporte sa commande
     *
     * « Certaines clés de statut n'existent que dans certaines situations ;
     * quand elles disparaissent, la charge utile les porte avec la valeur
     * `null` » — la documentation, mot pour mot. Une sonde débranchée, un tore
     * retiré : la commande qui les lisait n'a plus rien à lire, et la garder
     * afficherait pour toujours la dernière valeur mesurée.
     * ------------------------------------------------------------------- */
    $titre = 'un champ annoncé disparu n\'est plus lu, ses voisins restent';
    $fautes = array();
    $ctxDisparu = new MqttbeContexteEssaiGen2();
    $adapterDisparu = mqttbeAdapterGen2();
    mqttbeRejoueGen2($adapterDisparu, $ctxDisparu, $appareils['plus1pm']);
    $prefixeDisparu = $appareils['plus1pm']['prefixe'];
    $avantDisparu = empty($ctxDisparu->modeles) ? array()
        : mqttbeClesGen2($ctxDisparu->modeles[count($ctxDisparu->modeles) - 1]);
    if (!in_array('switch.0.energy', $avantDisparu, true)) {
        $fautes[] = 'la capture ne porte pas de compteur d\'énergie : le contrôle ne prouve rien.';
    }
    $adapterDisparu->onMessage($prefixeDisparu . '/events/rpc', json_encode(array(
        'src' => $appareils['plus1pm']['deviceinfo']['id'],
        'method' => 'NotifyStatus',
        'params' => array('ts' => 1789644700.0,
            'switch:0' => array('id' => 0, 'aenergy' => null, 'apower' => 11.0)),
    )), false, $ctxDisparu);
    $apresDisparu = empty($ctxDisparu->modeles) ? array()
        : mqttbeClesGen2($ctxDisparu->modeles[count($ctxDisparu->modeles) - 1]);
    if (in_array('switch.0.energy', $apresDisparu, true)) {
        $fautes[] = 'le compteur d\'énergie survit à sa disparition : la commande affichera '
            . 'éternellement la dernière valeur lue.';
    }
    if (!in_array('switch.0.power', $apresDisparu, true)) {
        $fautes[] = 'la puissance a disparu avec l\'énergie : un « null » ne concerne que la clé '
            . 'qu\'il porte.';
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 28. Une réponse sans « dst » est une réponse
     *
     * La page normative donne `dst` pour obligatoire ; les exemples engendrés
     * du même site le montrent absent — et ce sont exactement ceux dont la clé
     * s'appelle `params` au lieu de `result`. Exiger `dst` ferait donc jeter en
     * silence la réponse même que le repli prétend rattraper. La corrélation
     * par le numéro de requête suffit : ce numéro, nous seuls l'avons attribué.
     * ------------------------------------------------------------------- */
    $titre = 'une réponse sans « dst » est acceptée, celle d\'un autre client non';
    $fautes = array();
    $ctxSansDst = new MqttbeContexteEssaiGen2();
    $adapterSansDst = mqttbeAdapterGen2();
    $prefixeSansDst = $appareils['plus1pm']['prefixe'];
    $idSansDst = $appareils['plus1pm']['deviceinfo']['id'];
    $adapterSansDst->onTick($ctxSansDst);
    $adapterSansDst->onMessage($prefixeSansDst . '/online', 'true', true, $ctxSansDst);
    $adapterSansDst->onTick($ctxSansDst);
    $requeteSansDst = $ctxSansDst->derniereRequete($prefixeSansDst . '/rpc');
    if ($requeteSansDst === null) {
        $fautes[] = 'aucune question posée : le contrôle ne prouve rien.';
    } else {
        /* Celle d'un autre client, qui aurait recopié le « user_1 » des
         * exemples : elle ne nous est pas destinée. */
        $adapterSansDst->onMessage($requeteSansDst['src'] . '/rpc', json_encode(array(
            'id' => $requeteSansDst['id'], 'src' => $idSansDst, 'dst' => 'user_1',
            'result' => $appareils['plus1pm']['deviceinfo'])), false, $ctxSansDst);
        $ctxSansDst->avance(1);
        $adapterSansDst->onTick($ctxSansDst);
        $suite = $ctxSansDst->derniereRequete($prefixeSansDst . '/rpc');
        if ($suite !== null && $suite['method'] !== 'Shelly.GetDeviceInfo') {
            $fautes[] = 'la réponse adressée à « user_1 » a été prise pour la nôtre : sur un broker '
                . 'partagé, deux Jeedom se voleraient leurs réponses.';
        }
        /* Et la même, sans « dst » et avec « params » : elle est bien pour nous. */
        $adapterSansDst->onMessage($requeteSansDst['src'] . '/rpc', json_encode(array(
            'id' => $requeteSansDst['id'], 'src' => $idSansDst,
            'params' => $appareils['plus1pm']['deviceinfo'])), false, $ctxSansDst);
        $ctxSansDst->avance(1);
        $adapterSansDst->onTick($ctxSansDst);
        $suite = $ctxSansDst->derniereRequete($prefixeSansDst . '/rpc');
        if ($suite === null || $suite['method'] !== 'Shelly.GetComponents') {
            $fautes[] = 'une réponse sans « dst » est jetée : la conversation s\'arrête à sa '
                . 'première question, sans un mot dans le journal.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 29. Toutes les erreurs ne disent pas « méthode inconnue »
     *
     * Le repli GetStatus + GetConfig répond à UN cas : un micrologiciel qui ne
     * connaît pas `Shelly.GetComponents`, et qui le dit par un 404 « No handler
     * for ». Un `-103 INVALID ARGUMENT` dit autre chose ; le traiter de même
     * écrirait dans le journal une cause fausse, et c'est ce journal qu'on lira
     * le jour où la découverte n'aboutira pas.
     * ------------------------------------------------------------------- */
    $titre = 'une erreur qui n\'est pas un 404 ne se lit pas « méthode inconnue »';
    $fautes = array();
    $ctxErreur = new MqttbeContexteEssaiGen2();
    $adapterErreur = mqttbeAdapterGen2();
    $prefixeErreur = $appareils['plus1pm']['prefixe'];
    $idErreur = $appareils['plus1pm']['deviceinfo']['id'];
    $adapterErreur->onTick($ctxErreur);
    $adapterErreur->onMessage($prefixeErreur . '/online', 'true', true, $ctxErreur);
    $adapterErreur->onTick($ctxErreur);
    mqttbeRepondGen2($adapterErreur, $ctxErreur, $prefixeErreur, $idErreur,
                     $appareils['plus1pm']['deviceinfo']);
    $adapterErreur->onTick($ctxErreur);
    mqttbeRepondGen2($adapterErreur, $ctxErreur, $prefixeErreur, $idErreur, null,
                     array('code' => -103, 'message' => 'Invalid argument offset'));
    $ctxErreur->avance(1);
    $adapterErreur->onTick($ctxErreur);
    foreach ($ctxErreur->publications as $publication) {
        $decode = json_decode($publication['payload'], true);
        if (is_array($decode) && isset($decode['method']) && $decode['method'] === 'Shelly.GetStatus') {
            $fautes[] = 'un « -103 » déclenche le repli des vieux micrologiciels : les deux plus '
                . 'grosses réponses de l\'API sont demandées pour rien.';
            break;
        }
    }
    foreach ($ctxErreur->journal as $ligne) {
        if (strpos($ligne, 'ne connaît pas Shelly.GetComponents') !== false) {
            $fautes[] = 'le journal attribue à l\'appareil une ignorance qu\'il n\'a pas déclarée : '
                . '« ' . $ligne . ' ».';
            break;
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 30. Ce qui reste à dire quand le plugin ne peut rien faire
     *
     * Deux situations où l'équipement créé ne vaudra pas ce qu'il promet, et
     * où la seule chose utile est de le dire :
     *   `rpc_ntf` à `false` — toutes les commandes liront un topic que
     *     l'appareil ne publie plus. Le plugin ne touche pas à la configuration
     *     d'un appareil, et ce réglage n'est pas le sien ;
     *   un inventaire amputé — l'appareil annonce plus de composants qu'il
     *     n'en livre. Le publier comme « certain » serait un mensonge.
     * ------------------------------------------------------------------- */
    $titre = 'un réglage ou un inventaire qui trahit l\'équipement est dit, pas caché';
    $fautes = array();
    $appareilSourd = $appareils['plus1pm'];
    $trouve = false;
    foreach ($appareilSourd['composants'] as $index => $composant) {
        if ($composant['key'] === 'mqtt') {
            $appareilSourd['composants'][$index]['config']['rpc_ntf'] = false;
            $trouve = true;
        }
    }
    if (!$trouve) {
        $fautes[] = 'la capture ne porte pas de composant « mqtt » : le contrôle ne prouve rien.';
    }
    $ctxSourd = new MqttbeContexteEssaiGen2();
    mqttbeRejoueGen2(mqttbeAdapterGen2(), $ctxSourd, $appareilSourd);
    $dit = false;
    foreach ($ctxSourd->journal as $ligne) {
        if (strpos($ligne, 'rpc_ntf') !== false) {
            $dit = true;
        }
    }
    if (!$dit) {
        $fautes[] = 'un appareil dont les notifications sont coupées est découvert sans un mot : '
            . 'toutes ses commandes resteront vides, et le journal laissera croire à une panne du '
            . 'plugin.';
    }

    $ctxAmpute = new MqttbeContexteEssaiGen2();
    $adapterAmpute = mqttbeAdapterGen2();
    $prefixeAmpute = 'shellypro4pm-a8b0c1000042';
    $adapterAmpute->onTick($ctxAmpute);
    $adapterAmpute->onMessage($prefixeAmpute . '/online', 'true', true, $ctxAmpute);
    $adapterAmpute->onTick($ctxAmpute);
    mqttbeRepondGen2($adapterAmpute, $ctxAmpute, $prefixeAmpute, 'shellypro4pm-a8b0c1000042',
        array('id' => 'shellypro4pm-a8b0c1000042', 'mac' => 'A8B0C1000042',
              'model' => 'SPSW-004PE16EU', 'gen' => 2, 'ver' => '1.4.4', 'app' => 'Pro4PM'));
    $adapterAmpute->onTick($ctxAmpute);
    /* Il annonce quarante composants et en livre deux, puis plus rien. */
    mqttbeRepondGen2($adapterAmpute, $ctxAmpute, $prefixeAmpute, 'shellypro4pm-a8b0c1000042',
        array('components' => array(
            array('key' => 'switch:0', 'status' => array('id' => 0, 'output' => true),
                  'config' => array('id' => 0, 'name' => null)),
            array('key' => 'sys', 'status' => array('mac' => 'A8B0C1000042', 'uptime' => 12),
                  'config' => array())),
              'cfg_rev' => 9, 'offset' => 0, 'total' => 40));
    $adapterAmpute->onTick($ctxAmpute);
    mqttbeRepondGen2($adapterAmpute, $ctxAmpute, $prefixeAmpute, 'shellypro4pm-a8b0c1000042',
        array('components' => array(), 'cfg_rev' => 9, 'offset' => 2, 'total' => 40));
    $ctxAmpute->avance(1);
    $adapterAmpute->onTick($ctxAmpute);
    if (empty($ctxAmpute->modeles)) {
        $fautes[] = 'un inventaire amputé ne donne aucun équipement : ce qui est connu vaut mieux '
            . 'que rien.';
    } else {
        $modeleAmpute = $ctxAmpute->modeles[count($ctxAmpute->modeles) - 1];
        if ($modeleAmpute->confidence() === 'certain') {
            $fautes[] = 'deux composants sur quarante, et l\'équipement est « certain » : la '
                . 'confiance ne sert plus à rien si elle ne dit pas cela.';
        }
    }
    $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));

    /* ---------------------------------------------------------------------
     * 31. Une capture RÉELLE, rejouée telle quelle
     *
     * Tous les contrôles qui précèdent rejouent des conversations
     * reconstituées d'après la documentation : ils prouvent que l'adapter fait
     * ce qu'on a voulu, jamais qu'un Shelly réel réponde ainsi. Celui-ci est
     * d'une autre nature. Il rejoue l'octet qu'un Shelly 1 Mini Gen3 en
     * micrologiciel 2.0.0 a publié le 20 septembre 2026, anonymisé et rien de
     * plus — ordre des clés, valeurs nulles, pagination comprises.
     *
     * Et cette pagination-là, aucune lecture de la documentation ne permettait
     * de la deviner : l'appareil annonce QUATORZE composants, en livre ONZE à
     * l'offset 0, et les trois derniers à l'offset 11. Un adapter qui
     * supposerait une page entière, ou une taille de page fixe, perdrait
     * silencieusement `sys`, `wifi` et `ws` — c'est-à-dire la durée de
     * fonctionnement, le signal et rien de moins.
     * ------------------------------------------------------------------- */
    $titre = 'capture réelle : la conversation d\'un Gen3 en 2.0.0, rejouée octet pour octet';
    $fautes = array();
    $reelle = mqttbeLitJsonGen2('capture-reelle.json');
    if ($reelle === null || !isset($reelle['appareil']['deviceinfo'])) {
        $resultats[] = mqttbeIndecis($titre, 'tests/fixtures/shelly/gen2/capture-reelle.json est '
            . 'absent ou illisible.');
    } else {
        $vrai      = $reelle['appareil'];
        $prefixeR  = $vrai['deviceinfo']['id'];
        $ctxReel   = new MqttbeContexteEssaiGen2();
        $adapterR  = mqttbeAdapterGen2();
        $adapterR->onTick($ctxReel);
        $adapterR->onMessage($prefixeR . '/online', 'true', true, $ctxReel);
        $adapterR->onTick($ctxReel);

        /* On ne répond QUE ce que l'appareil a réellement répondu. Une question
         * que la capture ne couvre pas est une question qu'il n'a jamais reçue,
         * et l'inventer reviendrait à retomber dans la conversation imaginaire
         * que ce contrôle sert justement à quitter. */
        $demandes = array();
        for ($tour = 0; $tour < 8; $tour++) {
            $requete = $ctxReel->derniereRequete($prefixeR . '/rpc');
            if ($requete === null) {
                break;
            }
            $signature = $requete['method']
                . (isset($requete['params']['offset']) ? ':' . $requete['params']['offset'] : '');
            if (in_array($signature, $demandes, true)) {
                break;
            }
            $demandes[] = $signature;
            $resultat = null;
            if ($requete['method'] === 'Shelly.GetDeviceInfo') {
                $resultat = $vrai['deviceinfo'];
            } elseif ($requete['method'] === 'Shelly.GetComponents') {
                foreach ($vrai['pages'] as $page) {
                    if ((int) $page['offset'] === (int) $requete['params']['offset']) {
                        $resultat = $page;
                    }
                }
            }
            if ($resultat === null) {
                $fautes[] = 'l\'adapter demande « ' . $signature . ' », ce que l\'appareil réel n\'a '
                    . 'jamais eu à répondre : la pagination ne suit pas ce que la capture montre.';
                break;
            }
            mqttbeRepondGen2($adapterR, $ctxReel, $prefixeR, $prefixeR, $resultat);
            $ctxReel->avance(1);
            $adapterR->onTick($ctxReel);
        }

        $attendues = array('Shelly.GetDeviceInfo', 'Shelly.GetComponents:0', 'Shelly.GetComponents:11');
        if ($demandes !== $attendues) {
            $fautes[] = 'conversation « ' . implode(' | ', $demandes) . " » au lieu de «\u{a0}"
                . implode(' | ', $attendues) . ' ».';
        }

        if (empty($ctxReel->modeles)) {
            $fautes[] = 'aucun équipement : la conversation réelle n\'aboutit pas.';
        } else {
            $modeleReel = $ctxReel->modeles[count($ctxReel->modeles) - 1];
            if ($modeleReel->uid() !== 'shelly:a8b0c1000050') {
                $fautes[] = 'uid « ' . $modeleReel->uid() . ' » : la MAC de la capture est ailleurs.';
            }
            if ($modeleReel->confidence() !== 'certain') {
                $fautes[] = 'confiance « ' . $modeleReel->confidence() . ' » après une conversation '
                    . 'complète et paginée jusqu\'au bout.';
            }
            $clesReelles = mqttbeClesGen2($modeleReel);
            $exigees = array('online', 'switch.0.state', 'switch.0.on', 'switch.0.off',
                             'switch.0.toggle', 'switch.0.temperature', 'input.0.state',
                             'wifi.rssi', 'wifi.status', 'cloud.connected', 'sys.uptime',
                             'sys.ram_free', 'sys.restart', 'event.name', 'event.component');
            foreach ($exigees as $cleExigee) {
                if (!in_array($cleExigee, $clesReelles, true)) {
                    $fautes[] = 'commande « ' . $cleExigee . ' » absente (obtenues : '
                        . implode(', ', $clesReelles) . ').';
                }
            }
            /* L'appareil n'a QUE des mises à jour bêta en attente : sa clé
             * `available_updates` ne porte pas de `stable`. Créer la commande
             * reviendrait à afficher « null » pour toujours. C'est le seul
             * contrôle du dépôt où cette forme vienne d'un vrai appareil. */
            if (in_array('sys.update', $clesReelles, true)) {
                $fautes[] = 'une commande de mise à jour a été créée alors que l\'appareil ne '
                    . 'propose qu\'une bêta : elle n\'afficherait jamais rien.';
            }
            /* Un Shelly 1 Mini Gen3 n'a pas de wattmètre. */
            foreach (array('switch.0.power', 'switch.0.energy', 'switch.0.voltage') as $absente) {
                if (in_array($absente, $clesReelles, true)) {
                    $fautes[] = 'commande « ' . $absente . ' » inventée : cet appareil ne publie '
                        . 'aucune mesure de puissance.';
                }
            }

            /* Et la vraie notification, telle qu'elle est arrivée : elle ne
             * porte qu'un sous-ensemble de champs déjà connus, donc elle ne
             * doit RIEN reconstruire. */
            if (isset($vrai['notifystatus'])) {
                $avantNotif = count($ctxReel->modeles);
                $adapterR->onMessage($prefixeR . '/events/rpc',
                    json_encode($vrai['notifystatus']), false, $ctxReel);
                if (count($ctxReel->modeles) !== $avantNotif) {
                    $fautes[] = 'une notification de télémétrie ordinaire fait reconstruire le '
                        . 'modèle : sur un compteur d\'énergie, ce serait plusieurs fois par seconde.';
                }
            }
        }

        /*
         * Le repli des vieux micrologiciels, sur les mêmes charges utiles
         * réelles — et la limite qu'il porte, vérifiée sur un vrai appareil :
         * `Shelly.GetStatus` et `Shelly.GetConfig` n'énumèrent PAS les
         * composants dynamiques. Les deux capteurs BTHome appairés à cet
         * appareil figurent dans `Shelly.GetComponents` et dans aucune des deux
         * autres réponses.
         */
        if (isset($vrai['status'], $vrai['config'])) {
            $dynamiques = 0;
            foreach ($vrai['pages'] as $page) {
                foreach ($page['components'] as $composant) {
                    if (strpos($composant['key'], 'bthomedevice:') === 0) {
                        $dynamiques++;
                    }
                }
            }
            if ($dynamiques === 0) {
                $fautes[] = 'la capture ne porte aucun composant dynamique : le contrôle du repli '
                    . 'ne prouve rien.';
            }
            foreach (array('status', 'config') as $quoi) {
                foreach (array_keys($vrai[$quoi]) as $cleReponse) {
                    if (strpos($cleReponse, 'bthomedevice:') === 0) {
                        $fautes[] = 'Shelly.Get' . ucfirst($quoi) . ' énumère « ' . $cleReponse
                            . ' » : la capture contredit ce que le repli suppose.';
                    }
                }
            }
        }
        $resultats[] = empty($fautes) ? mqttbeOk($titre) : mqttbeEchec($titre, implode("\n", $fautes));
    }

    return $resultats;
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Découverte Shelly Gen2+', mqttbeControlesShellyGen2());
}
