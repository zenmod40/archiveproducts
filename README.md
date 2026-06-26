# Archive Products

Module PrestaShop pour filtrer les produits des catégories « Archives » **dans le listing produit du Back Office**, sans les supprimer. Un toggle 3 états (Masquer / Tout / Archives seules) s'injecte au-dessus de la grille produit. Et si besoin, des actions groupées permettent de désactiver ou réactiver en masse les produits archivés, par catégorie ou globalement, depuis la page de configuration.

> ℹ️ **Périmètre** : le toggle au-dessus de la grille n'affecte QUE le listing BO — vos clients ne voient aucun changement côté boutique. Si vous souhaitez aussi masquer ces produits côté front, utilisez les **actions groupées** (« Désactiver »/« Réactiver ») de la page de configuration : elles modifient l'attribut « actif » natif PrestaShop des produits visés, par catégorie ou en masse, de façon parfaitement réversible.

Compatible PrestaShop 1.7, 8 et 9. Module libre et open source sous licence GPL v3, par ZM40.

## Pourquoi ce module ?

Vos vieux produits qui ne sont plus vendus mais que vous gardez pour l'historique des commandes encombrent votre catalogue. Le filtrage par nom dans le BO retourne 200 résultats dont 180 obsolètes. Vous perdez du temps.

Avec Archive Products :

- Créez une (ou plusieurs) catégorie(s) « Archives »
- Affectez-y les produits à exclure du listing courant
- Le BO les masque automatiquement de la grille — mais ils restent en base, ils restent commandables si vous les reliez à une URL, ils gardent leur historique.

Un toggle en haut de la grille permet de basculer rapidement entre :
- **Masquer** (par défaut) — votre quotidien
- **Tout afficher** — vue d'ensemble
- **Archives seules** — pour faire le tri, réactiver, ou inventorier les zombies

## Fonctionnalités

- Configuration multi-catégories : choisissez une ou plusieurs catégories « Archives » via un arbre interactif avec recherche live, expand/collapse, multi-sélection visuelle.
- Toggle 3 états (Masquer / Tout / Archives seules) injecté au-dessus de la grille produit Symfony (PS 8/9).
- Filtre intelligent : les produits archivés s'affichent quand même si l'admin filtre explicitement sur une catégorie archive (cas du tri / réactivation).
- **Actions groupées par catégorie** : tableau récapitulatif (Actifs / Inactifs / Total) avec, pour chaque catégorie archive sélectionnée, des boutons « Désactiver tous les produits » et « Réactiver tous les produits ». Quand plusieurs catégories sont sélectionnées, deux boutons globaux « Tout désactiver » / « Tout réactiver » s'affichent en complément. Toutes les actions modifient l'attribut natif `active` du produit (équivalent au toggle natif PrestaShop produit par produit) — entièrement réversible.
- **Bouton « Retirer cette catégorie de la liste » par ligne** : sort une catégorie de la configuration sans toucher aux produits qu'elle contient (le filtre BO ne s'y applique plus, mais les produits gardent leur statut actif/inactif courant).
- Compatibilité legacy `AdminProductsController` pour PrestaShop 1.7 — filtre par nom / référence / EAN / ISBN / UPC / MPN.
- Aucune table SQL ajoutée par le module : la configuration tient dans `Configuration::updateValue` (clé `ARCHIVEPRODUCTS_CATEGORIES`).
- Désinstallation propre : la configuration disparaît, les produits restent strictement intacts (statut actif/inactif courant préservé).

## Compatibilité

- PrestaShop 1.7.x, 8.x, 9.x
- PHP 7.2 à 8.x
- Multiboutique et multilingue (FR / EN)

## Installation

1. Déposez le dossier `archiveproducts` dans le répertoire `modules/` de votre boutique (ou installez le ZIP via le back-office).
2. Installez le module depuis Modules > Gestionnaire de modules.
3. Allez dans la configuration du module et sélectionnez la ou les catégories « Archives ».

## Configuration

Tout se configure depuis la page du module :

1. Créez (ou choisissez) une catégorie « Archives » dans Catalogue > Catégories.
2. Modules > Archive Products > Configurer → cochez la(les) catégorie(s) dans l'arbre, sauvegardez.
3. Affectez vos produits obsolètes à cette catégorie (onglet « Associations » dans la fiche produit).

C'est tout. Le toggle apparaît automatiquement au-dessus de la grille produit du BO.

## Confidentialité

- Aucune télémétrie. Le module ne contacte aucun serveur ZM40 pour fonctionner.
- Vérification de version (notify-only) via l'API publique de GitHub, au maximum une fois par jour. Anonyme : aucune donnée de votre boutique n'est transmise. Vous pouvez la désactiver dans la configuration du module (interrupteur « réseau »).

## Support et services

Le code est offert. Le support gratuit se limite aux bugs reproductibles (issues GitHub). L'installation, la configuration sur mesure, l'adaptation à votre thème et les développements spécifiques sont des prestations : [zm40.com](https://zm40.com).

Une version compatible ThirtyBees / PrestaShop 1.6 peut être étudiée sur demande.

## Contribuer

Les pull requests sont les bienvenues. Merci d'ouvrir une issue avant les changements importants.

## Licence

GPL v3 — voir le fichier [LICENSE](LICENSE).
