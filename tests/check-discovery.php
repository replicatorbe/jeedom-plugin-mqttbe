<?php
/* Contrôle hors ligne du moteur de découverte — CONTRAT-J4 §3 et §4.
 *
 *   php tests/check-discovery.php
 *
 * Ni broker, ni Jeedom, ni base de données : le moteur ne connaît que
 * l'interface MqttbeAdapter et branche tout le reste — publication,
 * abonnement, journal, horloge, sortie vers Jeedom. C'est ce qui permet de
 * l'éprouver ici sur EXACTEMENT le code qui tourne en production, avec un
 * adapter factice à la place de Shelly et une horloge qu'on avance à la main
 * plutôt qu'une seconde d'attente par contrôle.
 *
 * Ce qui est vérifié n'a rien d'exotique, et c'est bien le problème : un
 * message livré au mauvais adapter, deux adapters qui se partagent une
 * mémoire, un parc réémis en entier à chaque redémarrage, un adapter qui lève
 * et emporte le démon avec lui — aucune de ces pannes ne se voit à la
 * relecture, et toutes se manifestent en production sous la forme d'un
 * symptôme qui ne désigne pas sa cause.
 *
 * Code de retour non nul dès qu'un contrôle échoue. */

require_once __DIR__ . '/outils.php';

$mqttbeMoteurFichier = mqttbeRacine() . '/resources/mqttbed/discovery/Engine.php';
if (is_readable($mqttbeMoteurFichier)) {
    require_once $mqttbeMoteurFichier;
}

/* -----------------------------------------------------------------------------
 * L'adapter factice.
 *
 * Déclaré sous condition : sans l'interface, la seule déclaration de la classe
 * serait une erreur fatale, et le contrôle tomberait au lieu de se dire « non
 * vérifiable ». Les sources du plugin s'écrivent encore, et un fichier absent
 * ne prouve rien.
 *
 * Il ne simule aucun protocole : il note ce qu'il reçoit et fait ce qu'on lui
 * dit de faire. Tout ce que le moteur promet à un adapter réel doit se
 * vérifier sur celui-ci, précisément parce qu'il ne sait rien faire d'autre.
 * -------------------------------------------------------------------------- */
if (interface_exists('MqttbeAdapter')) {

    class MqttbeAdapterFactice implements MqttbeAdapter {

        /* Trace commune à tous les exemplaires : c'est elle qui dit dans quel
         * ORDRE les adapters ont été servis, ce qu'aucun d'eux ne peut
         * constater seul. */
        public static $trace = array();

        public $identifiant;
        public $priorite;
        public $abonnements;

        public $recus = array();      // [topic, payload, retained]
        public $ticks = 0;
        public $relances = 0;         // battements où $ctx->rescan() valait true

        public $leveSurMessage = false;
        public $leveSurTick    = false;
        public $leveSurAbonnements = false;

        /* Deux crochets pour les contrôles qui veulent faire agir l'adapter :
         * une fonction ($adapter, $ctx, $topic, $payload, $retained). */
        public $surMessage = null;
        public $surTick    = null;

        public function __construct($_id, $_priorite = 100, $_abonnements = array()) {
            $this->identifiant = $_id;
            $this->priorite    = $_priorite;
            $this->abonnements = $_abonnements;
        }

        public function id()       { return $this->identifiant; }
        public function priority() { return $this->priorite; }

        public function subscriptions() {
            if ($this->leveSurAbonnements) {
                throw new RuntimeException('abonnements illisibles');
            }
            return $this->abonnements;
        }

        public function onMessage($_topic, $_payload, $_retained, $_ctx) {
            self::$trace[] = $this->identifiant;
            $this->recus[] = array($_topic, $_payload, $_retained);
            if ($this->leveSurMessage) {
                throw new RuntimeException('charge utile illisible');
            }
            if ($this->surMessage !== null) {
                call_user_func($this->surMessage, $this, $_ctx, $_topic, $_payload, $_retained);
            }
        }

        public function onTick($_ctx) {
            $this->ticks++;
            if ($_ctx->rescan()) {
                $this->relances++;
            }
            if ($this->leveSurTick) {
                throw new RuntimeException('battement impossible');
            }
            if ($this->surTick !== null) {
                call_user_func($this->surTick, $this, $_ctx);
            }
        }
    }
}

/* -----------------------------------------------------------------------------
 * Le banc : tout ce que la boucle principale fournit au moteur en production.
 * -------------------------------------------------------------------------- */
class MqttbeBancDecouverte {

    public $lignes       = array();   // [niveau, message]
    public $modeles      = array();   // tableaux remis « à Jeedom »
    public $publications = array();   // [topic, payload, qos, retain]
    public $resyncs      = 0;         // demandes de resynchronisation des abonnements
    public $brokerOk     = true;
    public $temps        = 1000.0;    // l'horloge, avancée à la main

    public function journal($_niveau, $_message) {
        $this->lignes[] = array($_niveau, $_message);
    }

    public function modele($_tableau) {
        $this->modeles[] = $_tableau;
    }

    public function publie($_topic, $_payload, $_qos, $_retain) {
        if (!$this->brokerOk) {
            return false;
        }
        $this->publications[] = array($_topic, $_payload, $_qos, $_retain);
        return true;
    }

    public function abonne() {
        $this->resyncs++;
    }

    public function horloge() {
        return $this->temps;
    }

    public function avance($_secondes) {
        $this->temps += $_secondes;
    }

    /* Vrai si une ligne de journal du niveau demandé contient ce texte. */
    public function dit($_texte, $_niveau = null) {
        foreach ($this->lignes as $ligne) {
            if ($_niveau !== null && $ligne[0] !== $_niveau) {
                continue;
            }
            if (strpos($ligne[1], $_texte) !== false) {
                return true;
            }
        }
        return false;
    }

    public function oublie() {
        $this->lignes = array();
        $this->modeles = array();
        $this->publications = array();
        $this->resyncs = 0;
    }
}

/* Un moteur neuf, branché sur un banc neuf. */
function mqttbeMoteurNeuf($_exclusions = '') {
    $config = new MqttbeConfig(array('host' => '192.0.2.10', 'exclude' => $_exclusions));
    $moteur = new MqttbeDiscoveryEngine($config);
    $banc   = new MqttbeBancDecouverte();
    $moteur->onLog(array($banc, 'journal'));
    $moteur->onModel(array($banc, 'modele'));
    $moteur->onPublish(array($banc, 'publie'));
    $moteur->onSubscribe(array($banc, 'abonne'));
    $moteur->useClock(array($banc, 'horloge'));
    return array($moteur, $banc);
}

/* Un modèle valide au sens du CONTRAT-J2 §2, et rien de plus : ce qui est
 * éprouvé ici, c'est le moteur, pas le modèle — qui a ses propres contrôles. */
function mqttbeModeleFactice($_uid, $_adapter = 'factice.a', $_nom = 'Appareil', $_unite = '') {
    return array(
        'identity' => array('adapter' => $_adapter, 'uid' => $_uid, 'confidence' => 'certain'),
        'meta'     => array('name' => $_nom, 'manufacturer' => 'Factice'),
        'channels' => array(array(
            'key'        => 'state',
            'capability' => 'switch.state',
            'name'       => 'État',
            'unit'       => $_unite,
            'source'     => array('topic' => 'factice/' . $_uid . '/state',
                                  'selector' => array('type' => 'raw')),
        )),
    );
}

function mqttbeVerifie($_titre, $_condition, $_explication) {
    return $_condition ? mqttbeOk($_titre) : mqttbeEchec($_titre, $_explication);
}

/* =============================================================================
 * 1. Distribution des messages
 * ========================================================================== */
function mqttbeControlesDistribution() {
    $resultats = array();

    list($moteur, $banc) = mqttbeMoteurNeuf();
    $a = new MqttbeAdapterFactice('factice.a', 100, array('parc/annonce', 'parc/+/etat'));
    $b = new MqttbeAdapterFactice('factice.b', 50,  array('autre/#'));
    $moteur->register($a);
    $moteur->register($b);
    $moteur->apply(array('enabled' => true));

    $moteur->onMessage('parc/annonce', '{"id":1}', true);
    $moteur->onMessage('parc/xyz/etat', 'on', false);
    $moteur->onMessage('autre/chose/ici', 'peu importe', false);
    $moteur->onMessage('rien/a/voir', 'personne', false);

    $resultats[] = mqttbeVerifie('un message n\'atteint que les adapters abonnés',
        count($a->recus) === 2 && count($b->recus) === 1
        && $a->recus[0][0] === 'parc/annonce' && $b->recus[0][0] === 'autre/chose/ici',
        'reçus : factice.a ' . count($a->recus) . ', factice.b ' . count($b->recus)
        . ' — un adapter qui reçoit ce qui ne le concerne pas finit par modéliser '
        . 'l\'appareil d\'un autre protocole, et le parc apparaît en double dans Jeedom.');

    $resultats[] = mqttbeVerifie('un topic sans adapter ne coûte aucune livraison',
        $moteur->stats()['delivered'] === 3,
        'livraisons comptées : ' . $moteur->stats()['delivered'] . ' pour 3 messages concernés '
        . 'et un message étranger.');

    /* Le drapeau « retenu » distingue « état rejoué par le broker » de « cela
     * vient de se produire ». Un adapter qui le perd fabrique de faux
     * événements à chaque démarrage du démon : il croit voir un bouton pressé
     * alors qu'il lit le souvenir de la dernière pression. */
    $resultats[] = mqttbeVerifie('le drapeau « retenu » arrive intact jusqu\'à l\'adapter',
        $a->recus[0][2] === true && $a->recus[1][2] === false,
        'reçu ' . var_export($a->recus[0][2], true) . ' pour un message retenu et '
        . var_export($a->recus[1][2], true) . ' pour un message ordinaire.');

    /* Deux adapters sur le même topic : le plus prioritaire d'abord, toujours,
     * quel que soit l'ordre d'enregistrement. C'est ce qui fait qu'un parc
     * mixte donne le même résultat d'une exécution à l'autre. */
    list($moteur, $banc) = mqttbeMoteurNeuf();
    MqttbeAdapterFactice::$trace = array();
    $faible = new MqttbeAdapterFactice('factice.z', 10, array('commun/+'));
    $fort   = new MqttbeAdapterFactice('factice.a', 100, array('commun/+'));
    $moteur->register($faible);   // enregistré en premier, et pourtant servi en second
    $moteur->register($fort);
    $moteur->apply(array('enabled' => true));
    $moteur->onMessage('commun/1', 'x', false);

    $resultats[] = mqttbeVerifie('le plus prioritaire est servi en premier',
        MqttbeAdapterFactice::$trace === array('factice.a', 'factice.z'),
        'ordre observé : ' . implode(' puis ', MqttbeAdapterFactice::$trace)
        . ' — la priorité tranche quand deux adapters revendiquent le même appareil ; '
        . 'un ordre qui dépend du chargement des fichiers rend la découverte imprévisible.');

    /* Découverte arrêtée : plus rien ne passe, et cela ne coûte rien. */
    $moteur->apply(array('enabled' => false));
    $avant = count($fort->recus);
    $moteur->onMessage('commun/1', 'x', false);
    $resultats[] = mqttbeVerifie('la découverte arrêtée ne distribue plus rien',
        count($fort->recus) === $avant && $moteur->subscriptions() === array(),
        'messages encore livrés, ou abonnements encore réclamés, alors que Jeedom a '
        . 'demandé l\'arrêt : la découverte continuerait de créer des équipements.');

    return $resultats;
}

/* =============================================================================
 * 2. La mémoire des adapters : cloisonnée et bornée
 * ========================================================================== */
function mqttbeControlesMemoire() {
    $resultats = array();

    list($moteur, $banc) = mqttbeMoteurNeuf();
    $a = new MqttbeAdapterFactice('factice.a', 100, array('parc/+'));
    $b = new MqttbeAdapterFactice('factice.b', 100, array('parc/+'));

    /* Les deux adapters emploient la MÊME clé, ce qui est le cas normal :
     * « candidat », « announce », « pending » sont des mots que tout le monde
     * écrit. Si la mémoire n'était pas cloisonnée, le second écraserait le
     * premier et chacun lirait l'état de l'autre. */
    $a->surMessage = function ($_moi, $_ctx) { $_ctx->remember('candidat', 'vu par a'); };
    $b->surMessage = function ($_moi, $_ctx) { $_ctx->remember('candidat', 'vu par b'); };
    $moteur->register($a);
    $moteur->register($b);
    $moteur->apply(array('enabled' => true));
    $moteur->onMessage('parc/1', 'x', false);

    $lu = array();
    $a->surMessage = function ($_moi, $_ctx) use (&$lu) { $lu['a'] = $_ctx->recall('candidat'); };
    $b->surMessage = function ($_moi, $_ctx) use (&$lu) { $lu['b'] = $_ctx->recall('candidat'); };
    $moteur->onMessage('parc/1', 'x', false);

    $resultats[] = mqttbeVerifie('deux adapters ne partagent pas leur mémoire',
        isset($lu['a']) && isset($lu['b']) && $lu['a'] === 'vu par a' && $lu['b'] === 'vu par b',
        'factice.a relit ' . var_export(isset($lu['a']) ? $lu['a'] : null, true)
        . ' et factice.b ' . var_export(isset($lu['b']) ? $lu['b'] : null, true)
        . ' — deux adapters qui se marchent dessus produisent des modèles hybrides, '
        . 'impossibles à rattacher à un appareil réel.');

    /* forget() n'atteint que la sienne. */
    $a->surMessage = function ($_moi, $_ctx) { $_ctx->forget('candidat'); };
    $b->surMessage = null;
    $moteur->onMessage('parc/1', 'x', false);
    $resultats[] = mqttbeVerifie('oublier chez soi ne vide pas la mémoire du voisin',
        $moteur->recallFor('factice.a', 'candidat') === null
        && $moteur->recallFor('factice.b', 'candidat') === 'vu par b',
        'après forget() chez factice.a, factice.b relit '
        . var_export($moteur->recallFor('factice.b', 'candidat'), true)
        . ' — un oubli qui traverse la cloison efface le travail d\'un autre protocole.');

    /* Le moteur dépose la relance dans la mémoire de l'adapter, sous une clé
     * qui porte son identifiant, en plus de $ctx->rescan(). Un adapter écrit
     * contre l'une des deux conventions et servi par l'autre ne ferait RIEN
     * quand on presse « relancer la découverte », et ne dirait rien non plus. */
    $resultats[] = mqttbeVerifie('la relance est aussi déposée dans la mémoire de l\'adapter',
        $moteur->recallFor('factice.a', 'factice.a:rescan') === true
        && $moteur->recallFor('factice.b', 'factice.b:rescan') === true,
        'la clé « <identifiant>:rescan » n\'a pas été déposée à l\'activation : un adapter '
        . 'qui lit sa relance dans sa mémoire ne provoquerait jamais d\'annonce, et le parc '
        . 'déjà connecté resterait invisible sans la moindre ligne de journal.');

    $resultats[] = mqttbeVerifie('une clé inconnue se relit en null',
        $moteur->recallFor('factice.a', 'jamais vue') === null,
        'recall() d\'une clé absente doit rendre null, et non une chaîne vide : '
        . 'un adapter distinguerait mal « rien mémorisé » de « mémorisé vide ».');

    /* Le plafond. Un adapter qui mémorise par topic vu — et il suffit d'un
     * appareil qui publie sur `.../<horodatage>` — ferait enfler le démon
     * toute la nuit sans que rien ne le dise. */
    list($moteur, $banc) = mqttbeMoteurNeuf();
    $c = new MqttbeAdapterFactice('factice.c', 100, array('parc/+'));
    $moteur->register($c);
    $moteur->apply(array('enabled' => true));
    $banc->oublie();

    $plafond = MqttbeDiscoveryEngine::MEMORY_MAX;
    /* L'activation a déjà déposé la clé de relance : le compte des refus part
     * de ce qui est déjà là, sinon ce contrôle dirait faux le jour où le
     * moteur déposera une clé de plus. */
    $deja  = $moteur->memoryCountFor('factice.c');
    $refus = 0;
    for ($i = 0; $i < $plafond + 10; $i++) {
        if (!$moteur->rememberFor('factice.c', 'cle-' . $i, $i)) {
            $refus++;
        }
    }
    $resultats[] = mqttbeVerifie('la mémoire d\'un adapter est plafonnée',
        $moteur->memoryCountFor('factice.c') === $plafond && $refus === (10 + $deja),
        $moteur->memoryCountFor('factice.c') . ' clé(s) mémorisées pour un plafond de ' . $plafond
        . ', ' . $refus . ' refus — sans plafond, un adapter qui mémorise par topic vu fait '
        . 'grossir le démon jusqu\'à ce que l\'OOM killer tranche la question à trois heures '
        . 'du matin, et c\'est la panne la plus difficile à comprendre de toutes.');

    $resultats[] = mqttbeVerifie('le plafond atteint est journalisé, pas subi en silence',
        $banc->dit('plafond', 'warning') && $banc->dit('factice.c'),
        'aucune ligne de journal ne dit que le plafond est atteint : le démon cesserait '
        . 'de mémoriser sans que personne ne puisse le constater, et l\'adapter paraîtrait '
        . 'simplement « oublier » des appareils.');

    /* Une clé déjà connue reste modifiable : un adapter arrivé au plafond doit
     * pouvoir rafraîchir ce qu'il sait, sinon il travaille sur du périmé. */
    $resultats[] = mqttbeVerifie('au plafond, une clé déjà connue reste modifiable',
        $moteur->rememberFor('factice.c', 'cle-0', 'neuf')
        && $moteur->recallFor('factice.c', 'cle-0') === 'neuf',
        'la mise à jour d\'une clé existante a été refusée au plafond : l\'adapter '
        . 'raisonnerait indéfiniment sur un état périmé.');

    /* Le plafond ne déborde pas d'un adapter sur l'autre. */
    $resultats[] = mqttbeVerifie('le plafond d\'un adapter n\'atteint pas les autres',
        $moteur->rememberFor('factice.autre', 'cle', 'x'),
        'un adapter saturé empêche les autres de mémoriser : une panne d\'un protocole '
        . 'arrêterait la découverte de tous les autres.');

    return $resultats;
}

/* =============================================================================
 * 3. Les modèles : idempotence et refus
 * ========================================================================== */
function mqttbeControlesModeles() {
    $resultats = array();

    list($moteur, $banc) = mqttbeMoteurNeuf();
    $a = new MqttbeAdapterFactice('factice.a', 100, array('parc/+'));
    $moteur->register($a);
    $moteur->apply(array('enabled' => true));

    $modele = mqttbeModeleFactice('factice:0001');
    $a->surMessage = function ($_moi, $_ctx) use ($modele) { $_ctx->emit($modele); };

    $moteur->onMessage('parc/1', 'x', false);
    $premier = count($banc->modeles);
    $moteur->onMessage('parc/1', 'x', true);
    $moteur->onMessage('parc/1', 'x', true);

    $resultats[] = mqttbeVerifie('un modèle identique n\'est pas renvoyé à Jeedom',
        $premier === 1 && count($banc->modeles) === 1 && $moteur->stats()['duplicates'] === 2,
        count($banc->modeles) . ' modèle(s) remis pour trois émissions identiques — les '
        . 'messages de découverte sont retenus, donc rejoués en entier à chaque démarrage '
        . 'du démon : sans cette comparaison d\'empreintes, Jeedom recevrait tout le parc à '
        . 'chaque redémarrage, et la fabrique le passerait en revue en entier.');

    /* Ce qui change vraiment doit passer. L'unité d'un canal se traduit par une
     * écriture en base côté Jeedom : elle entre dans l'empreinte. */
    $change = mqttbeModeleFactice('factice:0001', 'factice.a', 'Appareil', 'W');
    $a->surMessage = function ($_moi, $_ctx) use ($change) { $_ctx->emit($change); };
    $moteur->onMessage('parc/1', 'x', false);
    $resultats[] = mqttbeVerifie('un modèle modifié repart',
        count($banc->modeles) === 2,
        'un modèle dont un canal a changé n\'a pas été transmis : l\'appareil resterait '
        . 'dans Jeedom tel qu\'il était à la première découverte, et la correction '
        . 'n\'arriverait jamais.');

    /* Deux adapters peuvent légitimement décrire le même appareil — un Shelly
     * vu par son protocole natif et par Home Assistant Discovery. C'est Jeedom
     * qui arbitre par priorité ; confondre les empreintes ici ferait
     * disparaître le second en silence. */
    list($moteur, $banc) = mqttbeMoteurNeuf();
    $x = new MqttbeAdapterFactice('factice.x', 100, array('parc/+'));
    $y = new MqttbeAdapterFactice('factice.y', 50, array('parc/+'));
    $commun = mqttbeModeleFactice('factice:0001', 'factice.x');
    $x->surMessage = function ($_moi, $_ctx) use ($commun) { $_ctx->emit($commun); };
    $y->surMessage = function ($_moi, $_ctx) use ($commun) { $_ctx->emit($commun); };
    $moteur->register($x);
    $moteur->register($y);
    $moteur->apply(array('enabled' => true));
    $moteur->onMessage('parc/1', 'x', false);
    $resultats[] = mqttbeVerifie('l\'empreinte est retenue par adapter, pas par uid seul',
        count($banc->modeles) === 2,
        'le second adapter décrivant le même appareil a été confondu avec le premier : '
        . 'l\'arbitrage par priorité, qui se fait côté Jeedom, ne verrait jamais le '
        . 'modèle concurrent.');

    /* Relance : « rien n'a changé » n'est pas une réponse acceptable quand
     * l'utilisateur presse le bouton parce qu'il a supprimé un équipement par
     * erreur. */
    list($moteur, $banc) = mqttbeMoteurNeuf();
    $a = new MqttbeAdapterFactice('factice.a', 100, array('parc/+'));
    $modele = mqttbeModeleFactice('factice:0001');
    $a->surMessage = function ($_moi, $_ctx) use ($modele) { $_ctx->emit($modele); };
    $a->surTick = function ($_moi, $_ctx) {
        if ($_ctx->rescan()) {
            $_ctx->publish('parc/command', 'announce');
        }
    };
    $moteur->register($a);
    $moteur->apply(array('enabled' => true));
    $moteur->onMessage('parc/1', 'x', true);
    $moteur->onMessage('parc/1', 'x', true);
    $avant = count($banc->modeles);

    $moteur->apply(array('enabled' => true, 'rescan' => true));
    $moteur->onMessage('parc/1', 'x', true);
    $resultats[] = mqttbeVerifie('une relance fait repartir le parc',
        $avant === 1 && count($banc->modeles) === 2,
        'après « relancer la découverte », le même modèle n\'est pas reparti : un '
        . 'équipement supprimé par erreur dans Jeedom ne reviendrait qu\'au prochain '
        . 'redémarrage du démon.');

    $moteur->tick();
    $resultats[] = mqttbeVerifie('la relance est annoncée à l\'adapter, qui peut provoquer une annonce',
        $a->relances >= 1 && count($banc->publications) >= 1
        && $banc->publications[0][0] === 'parc/command',
        'l\'adapter n\'a pas vu $ctx->rescan() : un parc déjà connecté depuis des '
        . 'semaines n\'émettra plus jamais son annonce de démarrage, et resterait '
        . 'invisible — c\'est tout l\'intérêt d\'une découverte active.');

    $resultats[] = mqttbeVerifie('le drapeau de relance retombe après le battement',
        $moteur->rescanFor('factice.a') === false,
        'le drapeau reste armé : l\'adapter provoquerait une annonce générale du parc '
        . 'à chaque seconde, indéfiniment.');

    /* Un modèle invalide envoyé à Jeedom échouerait sur une erreur SQL qui ne
     * désigne pas sa cause, et l'adapter fautif ne serait jamais mis en cause. */
    list($moteur, $banc) = mqttbeMoteurNeuf();
    $a = new MqttbeAdapterFactice('factice.a', 100, array('parc/+'));
    $a->surMessage = function ($_moi, $_ctx) {
        /* uid vide : le logicalId de l'équipement. */
        $_ctx->emit(array('identity' => array('adapter' => 'factice.a', 'uid' => ''),
                          'channels' => array()));
    };
    $moteur->register($a);
    $moteur->apply(array('enabled' => true));
    $banc->oublie();
    $moteur->onMessage('parc/1', 'x', false);
    $resultats[] = mqttbeVerifie('un modèle invalide est refusé et le journal dit pourquoi',
        count($banc->modeles) === 0 && $moteur->stats()['refused'] === 1
        && $banc->dit('refusé', 'error'),
        'un modèle invalide est parti vers Jeedom, ou son refus est resté muet : '
        . 'l\'enregistrement échouerait côté Jeedom sur une erreur SQL, et personne ne '
        . 'remonterait jusqu\'à l\'adapter qui l\'a produit.');

    return $resultats;
}

/* =============================================================================
 * 4. Un adapter qui lève
 * ========================================================================== */
function mqttbeControlesResistance() {
    $resultats = array();

    list($moteur, $banc) = mqttbeMoteurNeuf();
    $casseur = new MqttbeAdapterFactice('factice.casse', 100, array('parc/+'));
    $casseur->leveSurMessage = true;
    $sain = new MqttbeAdapterFactice('factice.sain', 50, array('parc/+'));
    $moteur->register($casseur);
    $moteur->register($sain);
    $moteur->apply(array('enabled' => true));
    $banc->oublie();

    /* Si l'exception traversait le moteur, ce contrôle ne rendrait jamais la
     * main : le démon s'arrêterait, et Jeedom le relancerait toutes les
     * minutes sans que personne ne sache pourquoi. */
    $moteur->onMessage('parc/1', 'charge utile', false);

    $resultats[] = mqttbeVerifie('une exception d\'adapter ne traverse pas le moteur',
        $moteur->stats()['failures'] === 1,
        'l\'exception n\'a pas été comptée : rien ne garantit qu\'elle a été attrapée.');

    $resultats[] = mqttbeVerifie('elle est journalisée avec le nom de l\'adapter',
        $banc->dit('factice.casse', 'error') && $banc->dit('charge utile illisible'),
        'l\'erreur n\'apparaît pas dans le journal, ou n\'y désigne pas son adapter : '
        . 'la découverte s\'arrêterait à moitié sans laisser de trace exploitable.');

    $resultats[] = mqttbeVerifie('les autres adapters reçoivent quand même le message',
        count($sain->recus) === 1,
        'le voisin du fautif n\'a rien reçu : un adapter mal écrit condamnerait '
        . 'la découverte de tous les protocoles.');

    /* Un adapter fautif n'est pas mis au rebut : il revient au message
     * suivant, et son auteur a un journal qui le désigne. */
    $moteur->onMessage('parc/2', 'x', false);
    $resultats[] = mqttbeVerifie('un adapter fautif reste actif pour la suite',
        count($casseur->recus) === 2 && count($sain->recus) === 2,
        'l\'adapter a été écarté après son exception : une charge utile fautive suffirait '
        . 'à faire disparaître un protocole entier jusqu\'au redémarrage du démon.');

    /* Les plaintes répétées sont espacées : un adapter qui lève à chaque
     * message produirait deux mille lignes par seconde. */
    $lignesAvant = count($banc->lignes);
    for ($i = 0; $i < 50; $i++) {
        $moteur->onMessage('parc/' . $i, 'x', false);
    }
    $resultats[] = mqttbeVerifie('les plaintes répétées sont espacées',
        (count($banc->lignes) - $lignesAvant) <= 1,
        (count($banc->lignes) - $lignesAvant) . ' lignes pour 50 exceptions en une seconde : '
        . 'le journal deviendrait illisible au moment précis où il aurait quelque chose à '
        . 'apprendre, et le disque de la box se remplirait avant le matin.');

    /* Idem sur le battement, et le moteur doit continuer à battre. */
    list($moteur, $banc) = mqttbeMoteurNeuf();
    $casseur = new MqttbeAdapterFactice('factice.casse', 100, array('parc/+'));
    $casseur->leveSurTick = true;
    $sain = new MqttbeAdapterFactice('factice.sain', 50, array('parc/+'));
    $moteur->register($casseur);
    $moteur->register($sain);
    $moteur->apply(array('enabled' => true));
    $moteur->tick();
    $banc->avance(2.0);
    $moteur->tick();
    $resultats[] = mqttbeVerifie('une exception dans onTick() n\'arrête pas le battement',
        $casseur->ticks === 2 && $sain->ticks === 2,
        'battements : ' . $casseur->ticks . ' pour le fautif, ' . $sain->ticks . ' pour son '
        . 'voisin — un adapter qui lève dans son battement gèlerait les relances et les '
        . 'expirations de tous les autres.');

    /* Un subscriptions() qui lève ne doit pas emporter les abonnements des
     * autres adapters. */
    list($moteur, $banc) = mqttbeMoteurNeuf();
    $casseur = new MqttbeAdapterFactice('factice.casse', 100, array('parc/+'));
    $casseur->leveSurAbonnements = true;
    $sain = new MqttbeAdapterFactice('factice.sain', 50, array('bon/topic'));
    $moteur->register($casseur);
    $moteur->register($sain);
    $moteur->apply(array('enabled' => true));
    $resultats[] = mqttbeVerifie('un subscriptions() fautif ne coûte que ses propres topics',
        array_keys($moteur->subscriptions()) === array('bon/topic'),
        'abonnements obtenus : ' . implode(', ', array_keys($moteur->subscriptions()))
        . ' — un adapter qui échoue à dire ce qu\'il écoute ne doit pas rendre le démon '
        . 'sourd pour les autres.');

    return $resultats;
}

/* =============================================================================
 * 5. Les abonnements
 * ========================================================================== */
function mqttbeControlesAbonnements() {
    $resultats = array();

    /* Réunion, dédoublonnage, et refus de ce que le broker ne comprendrait
     * pas comme nous. */
    list($moteur, $banc) = mqttbeMoteurNeuf();
    $a = new MqttbeAdapterFactice('factice.a', 100, array('parc/annonce', 'parc/+/online'));
    $b = new MqttbeAdapterFactice('factice.b', 50, array('parc/annonce', 'autre/#', 'sport/a#'));
    $moteur->register($a);
    $moteur->register($b);
    $moteur->apply(array('enabled' => true));

    $obtenus = array_keys($moteur->subscriptions());
    sort($obtenus);
    $resultats[] = mqttbeVerifie('les abonnements réunissent ceux des adapters actifs, sans doublon',
        $obtenus === array('autre/#', 'parc/+/online', 'parc/annonce'),
        'obtenus : ' . implode(', ', $obtenus) . ' — un topic demandé par deux adapters '
        . 'doit être souscrit une fois, et un abonnement posé deux fois ferait rejouer '
        . 'par le broker tous les messages retenus de la branche.');

    $resultats[] = mqttbeVerifie('un filtre MQTT illégal est refusé et journalisé',
        !isset($moteur->subscriptions()['sport/a#']) && $banc->dit('invalide', 'warning'),
        '« sport/a# » a été accepté : le broker ne lui donnerait pas le sens qu\'on croit, '
        . 'et le moteur croirait écouter un topic auquel il n\'est pas abonné.');

    /* La liste d'exclusion porte d'office ce que Jeedom publie lui-même :
     * s'y abonner pour découvrir reviendrait à réimporter ses propres
     * équipements, un de plus à chaque tour. */
    list($moteur, $banc) = mqttbeMoteurNeuf("jeedom/#\nparc/prive/+");
    $a = new MqttbeAdapterFactice('factice.a', 100,
        array('parc/annonce', 'jeedom/etat', 'parc/prive/1'));
    $moteur->register($a);
    $moteur->apply(array('enabled' => true));
    $resultats[] = mqttbeVerifie('les topics exclus ne sont jamais souscrits',
        array_keys($moteur->subscriptions()) === array('parc/annonce')
        && $banc->dit('exclusion'),
        'obtenus : ' . implode(', ', array_keys($moteur->subscriptions()))
        . ' — un moteur de découverte branché sur ce que Jeedom publie lui-même '
        . 'réimporte ses propres équipements, indéfiniment.');

    /* Un abonnement demandé en cours de route : le topic de réponse d'un appel
     * RPC ne se connaît pas à l'activation. */
    list($moteur, $banc) = mqttbeMoteurNeuf('exclu/#');
    $a = new MqttbeAdapterFactice('factice.a', 100, array('parc/annonce'));
    $moteur->register($a);
    $moteur->apply(array('enabled' => true));
    $banc->oublie();

    $repondu = $moteur->subscribeFor('factice.a', 'mqttbe/jeton42/rpc');
    $resultats[] = mqttbeVerifie('un abonnement demandé en cours de route prévient la boucle',
        $repondu && isset($moteur->subscriptions()['mqttbe/jeton42/rpc']) && $banc->resyncs === 1,
        'abonnement accepté : ' . var_export($repondu, true) . ', resynchronisations : '
        . $banc->resyncs . ' — sans prévenir la boucle, le topic serait réclamé mais jamais '
        . 'posé, et la réponse de l\'appareil n\'arriverait jamais.');

    $moteur->onMessage('mqttbe/jeton42/rpc', '{"result":1}', false);
    $resultats[] = mqttbeVerifie('le message y arrive bien chez le demandeur',
        count($a->recus) === 1 && $a->recus[0][0] === 'mqttbe/jeton42/rpc',
        'le topic souscrit en cours de route n\'est pas distribué : l\'index de '
        . 'distribution n\'a pas été refait.');

    $resultats[] = mqttbeVerifie('un abonnement de circonstance exclu ou illégal est refusé',
        !$moteur->subscribeFor('factice.a', 'exclu/quelque/chose')
        && !$moteur->subscribeFor('factice.a', 'a+b'),
        'un topic exclu par la configuration, ou un filtre illégal, a été accepté en '
        . 'cours de route : les deux garde-fous de l\'activation seraient contournables.');

    /* Un adapter qu'on arrête rend tout : ses abonnements de circonstance et
     * sa mémoire. */
    $moteur->rememberFor('factice.a', 'candidat', 'x');
    $moteur->apply(array('enabled' => true, 'adapters' => array()));
    $resultats[] = mqttbeVerifie('un adapter arrêté rend ses abonnements et sa mémoire',
        $moteur->subscriptions() === array() && $moteur->memoryCountFor('factice.a') === 0,
        'un adapter éteint garde des abonnements ou un état : il reprendrait plus tard '
        . 'sur des candidats qui n\'existent peut-être plus.');

    /* Un adapter inconnu demandé par Jeedom ne doit pas passer inaperçu. */
    list($moteur, $banc) = mqttbeMoteurNeuf();
    $moteur->register(new MqttbeAdapterFactice('factice.a', 100, array('parc/+')));
    $reponse = $moteur->apply(array('enabled' => true, 'adapters' => array('factice.a', 'shelly.gen9')));
    $resultats[] = mqttbeVerifie('un adapter inconnu est signalé, sans empêcher les autres',
        $reponse['adapters'] === array('factice.a') && $reponse['unknown'] === array('shelly.gen9')
        && $banc->dit('inconnu', 'warning'),
        'la réponse à l\'ordre `discovery` ne dit pas quels adapters ont réellement été '
        . 'activés : Jeedom afficherait une découverte en marche pour un adapter qui '
        . 'n\'existe pas.');

    return $resultats;
}

/* =============================================================================
 * 6. Le battement
 * ========================================================================== */
function mqttbeControlesBattement() {
    $resultats = array();

    list($moteur, $banc) = mqttbeMoteurNeuf();
    $a = new MqttbeAdapterFactice('factice.a', 100, array('parc/+'));
    $moteur->register($a);
    $moteur->apply(array('enabled' => true));

    /* Vingt tours de boucle dans la même seconde : un seul battement. La
     * boucle tourne vingt fois par seconde au repos, et bien plus sous
     * charge — c'est au moteur de tenir le rythme, pas à elle. */
    for ($i = 0; $i < 20; $i++) {
        $moteur->tick();
    }
    $apresUneSeconde = $a->ticks;

    $banc->avance(0.5);
    $moteur->tick();
    $apresUneDemie = $a->ticks;

    $banc->avance(0.6);
    $moteur->tick();

    $resultats[] = mqttbeVerifie('onTick() est appelé au plus une fois par seconde',
        $apresUneSeconde === 1 && $apresUneDemie === 1 && $a->ticks === 2,
        'battements : ' . $apresUneSeconde . ' après vingt tours dans la même seconde, '
        . $apresUneDemie . ' après une demi-seconde, ' . $a->ticks . ' après 1,1 s — un '
        . 'battement par tour de boucle, c\'est vingt relances par seconde sur un parc '
        . 'entier.');

    /* Découverte arrêtée : plus de battement du tout. */
    $moteur->apply(array('enabled' => false));
    $banc->avance(5.0);
    $moteur->tick();
    $resultats[] = mqttbeVerifie('la découverte arrêtée ne bat plus',
        $a->ticks === 2,
        'les adapters battent encore alors que Jeedom a demandé l\'arrêt : ils '
        . 'continueraient de publier sur le broker.');

    /* La publication passe par la boucle : sans broker, elle échoue proprement
     * et l'adapter réessaiera. */
    list($moteur, $banc) = mqttbeMoteurNeuf();
    $a = new MqttbeAdapterFactice('factice.a', 100, array('parc/+'));
    $rendu = array();
    $a->surTick = function ($_moi, $_ctx) use (&$rendu) {
        $rendu[] = $_ctx->publish('parc/command', 'announce');
    };
    $moteur->register($a);
    $moteur->apply(array('enabled' => true));
    $moteur->tick();
    $banc->brokerOk = false;
    $banc->avance(2.0);
    $moteur->tick();

    $resultats[] = mqttbeVerifie('publish() dit franchement quand le broker manque',
        count($rendu) === 2 && $rendu[0] === true && $rendu[1] === false
        && count($banc->publications) === 1,
        'valeurs rendues : ' . implode(', ', array_map(function ($_v) { return var_export($_v, true); }, $rendu))
        . ' — un adapter qui croit son annonce partie alors que le broker est absent '
        . 'attendra une réponse qui ne viendra jamais, et le parc restera invisible.');

    /* L'horloge du contexte est celle du moteur : c'est ce qui rend les
     * expirations éprouvables sans attendre. */
    $vu = array();
    $a->surTick = function ($_moi, $_ctx) use (&$vu) { $vu[] = $_ctx->now(); };
    $banc->avance(2.0);
    $moteur->tick();
    $resultats[] = mqttbeVerifie('$ctx->now() suit l\'horloge du moteur',
        count($vu) === 1 && abs($vu[0] - $banc->temps) < 0.001,
        'l\'adapter lit une heure qui n\'est pas celle du moteur : aucune expiration de '
        . 'candidat ne serait vérifiable hors ligne.');

    return $resultats;
}

/* -------------------------------------------------------------------------- */

function mqttbeControlesDecouverte() {
    if (!interface_exists('MqttbeAdapter') || !class_exists('MqttbeDiscoveryEngine')) {
        return array(mqttbeIndecis('moteur de découverte',
            'resources/mqttbed/discovery/Engine.php (ou Adapter.php) n\'existe pas encore.'));
    }
    return array_merge(
        mqttbeControlesDistribution(),
        mqttbeControlesMemoire(),
        mqttbeControlesModeles(),
        mqttbeControlesResistance(),
        mqttbeControlesAbonnements(),
        mqttbeControlesBattement()
    );
}

if (mqttbeAppelDirect(__FILE__)) {
    mqttbeSortieAutonome('Moteur de découverte', mqttbeControlesDecouverte());
}
