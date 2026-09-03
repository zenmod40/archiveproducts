# Archive Products — Changelog

Toutes les modifications notables de ce module sont documentées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/) et le module suit le [Versionnement sémantique](https://semver.org/lang/fr/).

## [1.0.3] - 2026-09-03

### Corrigé

- **Catégories désactivées absentes de l'arbre de sélection.** `Category::getNestedCategories()` et `Category::getCategories()` étaient appelées avec le filtre « actives uniquement », ce qui empêchait de cibler une catégorie Archives désactivée.
- **Filtre BO « Masquer / Tout afficher / Archives seules » sans effet sur le total et la pagination (PS 8/9, grid Symfony).** La clause d'exclusion/inclusion n'était appliquée qu'à la requête de résultats, jamais à la requête de comptage (`count_query_builder`), donc le nombre de produits affiché et la pagination ne changeaient jamais selon le mode choisi.

## [1.0.2] - 2026-08-21

### Modifié
- **Licence : GPL v3 vers Open Software License 3.0 (OSL-3.0).** Le cœur de PrestaShop est publié sous OSL-3.0, licence notoirement incompatible avec la GPL quelle que soit sa version. Un module ne pouvant fonctionner sans le cœur, la combinaison des deux ne peut satisfaire les deux copyleft à la fois, ce qui plaçait quiconque redistribue une boutique dans une situation insoluble. L'OSL-3.0 lève l'ambiguïté, aligne le module sur la licence de l'écosystème, et conserve ce qui comptait : l'obligation d'attribution et le partage des modifications. Les versions déjà publiées restent régies par la licence sous laquelle elles ont été distribuées.

## [1.0.1] - 2026-06-24

### Ajouté

- Première publication open source (licence GPL v3).
- **Actions groupées par catégorie Archives** : tableau récapitulatif (Actifs / Inactifs / Total) avec boutons par ligne pour désactiver ou réactiver tous les produits d'une catégorie donnée. Plus un bloc « Tout désactiver / Tout réactiver » global quand plusieurs catégories sont sélectionnées.
- Bandeau d'aide en haut de la config rappelant que le module agit **uniquement** sur le listing du Back Office (zéro impact côté boutique).
- Bloc pédagogique « Comment ça fonctionne » dans la page de configuration : 4 étapes claires pour archiver / désarchiver un produit.
- Brique partagée `Zm40CommonAp` v1.3 : vérification de version notify-only via GitHub Releases, footer d'attribution, bloc « Écosystème ZM40 » avec hiérarchie de boutons unifiée (CTA primaire + bouton secondaire outline) et raccourci « Configurer » direct vers la page de config des modules déjà installés.
- Section « À propos » dans la page de configuration : module GPL v3, lien vers le dépôt GitHub, mention ZM40 / Magic Garden.
- Compatibilité explicite étendue à PrestaShop 9.

### Sécurité

- Action groupée par catégorie : la cible (`arp_target_category`) est validée contre la config locale avant l'UPDATE — un ID hors config est refusé silencieusement (anti-injection si un admin BO était compromis).

### Retiré

- Système de vérification de licence Ed25519 / serveur ZM40 (le module est désormais 100 % autonome, aucun appel réseau hors vérification de version GitHub opt-out).
- Header propriétaire et code obfusqué — le code source est désormais le livrable.

### Modifié

- Licence changée de AFL-3.0 (proprietary distribution) vers **GPL-3.0-or-later** (open source).
- Description du module clarifiée : usage cible, comportement de filtrage, conservation des produits.
- `getContent()` simplifié : la sauvegarde est immédiate (plus de pré-contrôle de licence), avec rafraîchissement automatique du feed d'écosystème.

## [1.0.0] - 2026-04-15

### Ajouté

- Première version interne.
- Configuration multi-catégories : arbre interactif avec recherche live, expand/collapse, multi-sélection.
- Toggle 3 états (Masquer / Tout afficher / Archives seules) au-dessus de la grille produit Symfony (PS 8/9).
- Filtre intelligent : les produits archivés s'affichent quand l'admin filtre explicitement sur une catégorie archive.
- Compatibilité legacy `AdminProductsController` pour PrestaShop 1.7.
