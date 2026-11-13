# Stock de consommables — Snipe-IT 8.8.3

## Périmètre

Cette branche part du tag **v8.8.3**, commit `621e30486a59304f2a5288fb62c4aa91b08e0a84`.
Elle porte le module de `production/8.1.18` (commit `240703d7bdd527950800d05bdd160825b30f587f`) sur les dépendances officielles 8.8.3. Elle ne met pas à niveau MariaDB.
La mise à niveau de Snipe-IT applique également ses migrations amont, notamment les commandes et les sociétés multiples. Leurs changements de contrats API doivent être vérifiés avec l’intégration réelle.
Le Dockerfile d’origine construit le code de cette branche. Aucun volume ou fichier Compose de production n’est modifié.

Un produit représente une référence de consommable. Chaque lot porte son numéro et sa date de péremption.
Les quantités sont réparties entre des emplacements : réserve, véhicule ou sac.
Un sac peut avoir un véhicule comme parent. Déplacer le sac change son parent, sans créer de mouvements de chacune de ses unités.
Le filtre par emplacement inclut ses descendants et compte chaque unité une seule fois.

## Conservation des données et compatibilité

La migration propre au module ajoute uniquement les tables `consumable_stock_*`. Elle ne modifie aucune table ni ligne existante.
Lors d’une nouvelle installation, le module est désactivé ; chaque produit s’active volontairement.
Lors d’une mise à niveau depuis notre 8.1.18, les réglages, activations et tables existants sont conservés. La migration du module garde son identité et ne se rejoue pas.
Les migrations amont de recalcul de quantité excluent les produits suivis par lots : leur registre reste la source de vérité.

Pour les produits non activés, le comportement natif de Snipe-IT 8.8.3 reste disponible.
L’activation conserve la fiche produit, ses identifiants, ses distributions et ses journaux.
Elle crée un stock initial **à identifier**, égal à la quantité totale historique moins le nombre de distributions.
Aucune date n’est inventée. Identifier ce stock le répartit dans les vrais lots, sans enregistrer de nouvelle réception.

Les anciennes distributions ne prouvent pas que le matériel existe encore. Les unités effectivement retrouvées dans les véhicules/sacs
sont reprises après comptage physique par une **correction positive**, avec lot, date, emplacement et motif.
Ne pas les ajouter si elles figurent déjà dans le stock initial restant.

**Après activation**, les ajustements natifs de quantité, la consommation native des anciennes distributions, l’archivage, les sorties, changements de quantité, suppressions et distributions via les anciens kits sont bloqués pour ce produit.
L’intégration qui effectue ces opérations doit utiliser la nouvelle API avant d’activer le produit concerné.
Les modifications descriptives restent possibles. Une copie de produit n’embarque pas ses lots.
La quantité historique est maintenue comme projection : stock physique actuel + distributions historiques conservées.
La quantité disponible exposée par le modèle devient le stock utilisable, tous emplacements confondus.
Un changement direct du total ou du nombre de distributions en dehors du registre bloque les nouveaux mouvements jusqu’au rapprochement.

Désactiver le module fige ses mouvements et alertes. Cela ne supprime rien et ne remet pas les produits activés dans leur ancien mode.

## Utilisation

1. Dans **Administration → Notifications**, activer le module. Régler le délai avant péremption (jours) et l’intervalle des rappels.
2. Depuis une fiche consommable, ouvrir **Lots et stocks de consommables**, puis **Emplacements de stock**.
3. Créer la réserve, les véhicules et les sacs. Ils peuvent être associés à un matériel ou à un lieu existant.
4. Pour chaque produit inventorié, activer le suivi avec son unité de comptage et indiquer si une péremption est obligatoire.
5. Identifier le stock initial dans ses lots réels, puis le répartir par transfert. Reprendre séparément les anciennes distributions encore présentes.
6. Enregistrer les réceptions, transferts/retours, utilisations, retraits et corrections dans la page de stock.

Un transfert ne consomme rien. Seule l’utilisation ou le retrait diminue le stock physique global.
La suggestion FEFO choisit les lots utilisables expirant en premier à l’emplacement source.
L’opérateur doit confirmer que les unités manipulées correspondent au lot choisi. Il peut sélectionner un autre lot utilisable.
Une correction négative ou un retrait exige un lot explicite.
Un lot périmé, en quarantaine ou non identifié reste présent physiquement jusqu’à son retrait, mais ne contribue pas au stock utilisable.
La date de péremption est inclusive : le lot est considéré périmé à partir du lendemain, dans le fuseau horaire de l’application.

Les minimums s’appliquent au stock **direct** de chaque emplacement, hors descendants.
Pour contrôler le contenu d’un sac, définir son minimum sur le sac ; sa relocalisation préserve ce minimum.
Le minimum global historique du produit reste utilisable ; il porte désormais sur le total utilisable du produit activé.
Les réceptions/corrections sont limitées par les plafonds historiques de Snipe-IT (99 999 unités au total de la projection).

## Alertes

Les destinataires et le transport de courrier sont ceux des notifications existantes.
Les notifications globales et le module doivent être activés tous les deux.
Le contrôle quotidien couvre les quantités présentes en réserve, véhicules et sacs, y compris les lots en quarantaine.
Un délai de 0 déclenche à la date de péremption. Un passage à l’état périmé déclenche une nouvelle alerte.
Les rappels cessent lorsque le stock du lot est nul ; un échec d’envoi ne marque pas l’alerte comme envoyée.
Le stock à date inconnue est visible comme indisponible dans l’interface, sans date d’alerte inventée.

Vérification sans envoyer ni marquer les alertes :

```sh
php artisan snipeit:consumable-stock-alerts --dry-run
php artisan schedule:list
```

Le planificateur Laravel existant doit fonctionner. Ne pas ajouter un second planificateur si Docker l’exécute déjà.
Sur plusieurs instances, partager le cache utilisé pour le verrou d’alerte et conserver un seul ordonnanceur.
L’envoi SMTP et sa confirmation ne forment pas une transaction atomique : un arrêt juste après envoi peut entraîner un doublon au prochain contrôle.

## API pour les produits activés

Les routes gardent l’authentification et les permissions de l’API Snipe-IT :

| Méthode | Route |
| --- | --- |
| GET | `/api/v1/consumables/{id}/stock` |
| POST | `/api/v1/consumables/{id}/stock` |
| GET / POST | `/api/v1/consumables/stock/places` |
| PUT | `/api/v1/consumables/stock/places/{place}` |

L’historique GET accepte `offset` et `limit` (30 par défaut, 100 maximum).
Les commandes natives 8.8.3 conservent les anciennes références d’achat ; les réceptions par lot restent journalisées dans les mouvements du module.

Chaque POST de stock contient `operation_id` (UUID unique), `action` et `note`.
Une relance conserve le même UUID **et les mêmes données** ; elle ne doit pas doubler le mouvement.
Des données différentes avec un UUID déjà utilisé sont refusées.
Les réponses utilisent l’enveloppe Snipe-IT : **toujours vérifier `status`**, car des validations renvoient HTTP 200 avec `status: error`.

| Action | Champs principaux supplémentaires |
| --- | --- |
| enable | unit, expiry_required, to_place_id |
| receive | number, expires_on, quantity, to_place_id ; reference, received_on, supplier_id facultatifs |
| identify | lot_id du stock initial, from_place_id, number, expires_on, quantity |
| transfer | from_place_id, to_place_id, quantity ; lot_id facultatif (FEFO sinon) |
| consume | from_place_id, quantity ; lot_id facultatif (FEFO sinon) |
| withdraw | from_place_id, lot_id, quantity |
| adjust | quantity signée ; en plus : number, expires_on, to_place_id ; en moins : lot_id, from_place_id |
| amend_lot | lot_id, number, expires_on |
| quarantine | lot_id, quarantined |
| minimum | to_place_id, quantity (0 désactive le minimum) |

Les mouvements nécessitent le droit de lecture et le droit de sortie pour transfer/consume ; les autres actions exigent le droit de modification.
Les emplacements sont administrés par les superutilisateurs. Le cloisonnement par société existant s’applique.

## Validation et déploiement

La CI construit l’image Docker sans la publier, teste MariaDB 10.6 et 10.11, compile les vues,
exécute les tests du module et les régressions des consommables/notifications.
Elle vérifie aussi une véritable migration depuis notre application 8.1.18, avec lots, réserves, véhicules, sacs, distributions, mouvements et réglages déjà présents et deux processus concurrents effectuant des sorties/reprises du même UUID.
Les workflows historiques MySQL/SQLite continuent de fonctionner.
Ces tests ne remplacent pas une répétition sur une copie de **votre** production et sa version mineure exacte de MariaDB.

Avant tout déploiement :
- Identifier le commit/image réellement déployé et les éventuelles personnalisations à conserver. Cette branche part du tag officiel ; elle n’importe pas des personnalisations inconnues.
- Conserver une sauvegarde cohérente de MariaDB, des fichiers persistants, de la configuration et de la clé APP_KEY ; vérifier sa restauration dans un environnement isolé.
- Utiliser cette copie avec les envois de courrier dirigés vers un collecteur de test et sans intégration sortante vers la production.
- Construire une image immuable depuis le commit validé ; conserver l’image précédente. Ne pas utiliser une image amont `latest`.
- Répéter l’installation avec le module désactivé. Comparer quantités, affectations, historiques et résultats des intégrations.
- Vérifier la liste des migrations amont 8.8.3. Si le module existe déjà, `2026_11_13_100000_create_consumable_stock_tables` doit rester marquée comme exécutée.
- Valider le parcours métier complet sur des données de test : plusieurs lots/dates, sac déplacé, utilisation, péremption, retrait, seuil et notification.
- Prévoir une courte fenêtre maîtrisée pour les migrations. Ne pas exécuter simultanément des instances de l’ancien et du nouveau code pendant l’activation.
- Activer un produit uniquement après inventaire et adaptation de ses intégrations, en suspendant ses écritures historiques durant la bascule.

L’installation ne promet pas une absence totale d’interruption : le redémarrage Docker, les migrations et le volume réel de données doivent être vérifiés sur cette copie.
Ne pas exécuter `migrate:fresh`, `migrate:refresh`, une restauration ancienne sur la base courante ou un arrêt Docker avec suppression des volumes.

## Retour arrière

**Un retour de 8.8.3 vers 8.1.18 ne se fait pas en remettant simplement l’ancienne image** : les migrations amont renomment des colonnes. Préparer et répéter un retour cohérent code/base/fichiers avant la bascule, sans perdre les opérations saisies depuis.
La migration refuse un rollback destructif dès que des données/configurations du module existent.

Après activation, **ne pas simplement remettre l’ancienne image** : elle ignore les lots, les stocks en véhicule/sac et leurs protections.
Désactiver le module pour figer les écritures, conserver toutes les tables et utiliser une version corrigée comprenant le module.
Un retour au fonctionnement ancien exige une réconciliation explicite et testée des mouvements effectués depuis la bascule.
Ne pas restaurer le snapshot antérieur par-dessus les opérations nouvelles : cela les perdrait.
