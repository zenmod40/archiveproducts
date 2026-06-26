<?php
/**
 * Archive Products — Filtre les produits des catégories « Archives » dans le
 * listing produit du Back Office PrestaShop. Toggle 3 états (Masquer / Tout /
 * Archives seules) injecté au-dessus de la grille. Compatible PS 1.7 / 8 / 9.
 *
 * @author    ZM40 — Nicolas Michaud (Magic Garden)
 * @copyright 2026 Nicolas Michaud — ZM40 / Magic Garden
 * @license   GPL-3.0-or-later
 * @link      https://zm40.com/archiveproducts/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/lib/zm40/Zm40CommonAp.php';

class ArchiveProducts extends Module
{
    public function __construct()
    {
        $this->name = 'archiveproducts';
        $this->tab = 'administration';
        $this->version = '1.0.1';
        $this->author = 'ZM40';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Archive Products');
        $this->description = $this->l('Masque les produits des catégories « Archives » du listing produit BO, avec boutons filtrage.');
        $this->confirmUninstall = $this->l('Désinstaller Archive Products ? La configuration sera supprimée, les produits restent intacts.');
    }

    public function install()
    {
        if (!parent::install()
            || !$this->registerHook('actionProductGridQueryBuilderModifier')
            || !$this->registerHook('actionAdminProductsListingFieldsModifier')
            || !$this->registerHook('displayBackOfficeHeader')
        ) {
            return false;
        }
        Configuration::updateValue('ARCHIVEPRODUCTS_CATEGORIES', '');
        Configuration::updateValue('ZM40_NET_ENABLED', 1);
        // Force un refresh du feed ZM40 au prochain rendu (sinon l'écosystème
        // resterait sur les modules connus avant install).
        Zm40CommonAp::clearFeedCache();
        return true;
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('ARCHIVEPRODUCTS_CATEGORIES');
    }

    /**
     * Configuration du module dans le BO.
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitArchiveProducts')) {
            $selectedCategories = Tools::getValue('ARCHIVEPRODUCTS_CATEGORIES', []);
            if (!is_array($selectedCategories)) {
                $selectedCategories = array_filter(array_map('trim', explode(',', (string) $selectedCategories)));
            }
            $selectedCategories = array_values(array_unique(array_filter(array_map('intval', $selectedCategories))));
            $categoryIds = implode(',', $selectedCategories);
            Configuration::updateValue('ARCHIVEPRODUCTS_CATEGORIES', $categoryIds);
            // Sauvegarde = bon moment pour rafraîchir le feed ZM40 (UX : si un
            // nouveau module a été publié, on l'affiche dès la prochaine ouverture).
            Zm40CommonAp::clearFeedCache();
            $output .= $this->displayConfirmation($this->l('Configuration mise à jour'));
        }

        // Actions groupées : (dés)activer en masse. Scope optionnel via
        // arp_target_category (1 ID) — sinon applique à toutes les archives.
        if (Tools::isSubmit('arp_bulk_deactivate') || Tools::isSubmit('arp_bulk_reactivate')) {
            $target = Tools::isSubmit('arp_bulk_deactivate') ? 0 : 1;
            $scopeId = (int) Tools::getValue('arp_target_category');
            $n = $this->bulkUpdateArchivedActive($target, $scopeId ?: null);
            $tpl = $target === 0
                ? $this->l('%d produit(s) désactivé(s).')
                : $this->l('%d produit(s) réactivé(s).');
            $output .= $this->displayConfirmation(sprintf($tpl, $n));
        }

        // Retrait d'une catégorie de la liste Archives (depuis le tableau actions).
        if (Tools::isSubmit('arp_remove_category')) {
            $catToRemove = (int) Tools::getValue('arp_target_category');
            if ($catToRemove > 0) {
                $current = $this->getArchiveCategoryIds();
                if (in_array($catToRemove, $current, true)) {
                    $new = array_values(array_diff($current, [$catToRemove]));
                    Configuration::updateValue('ARCHIVEPRODUCTS_CATEGORIES', implode(',', $new));
                    $output .= $this->displayConfirmation(
                        $this->l('Catégorie retirée de la liste Archives. Les produits ne sont pas affectés.')
                    );
                }
            }
        }

        return $output
            . $this->renderAdminHeader()
            . $this->renderUpdateNotice()
            . $this->renderConfigPanel()
            . $this->renderEcosystem()
            . $this->renderAboutPanel()
            . $this->renderFooter();
    }

    /**
     * Compte les produits actifs / inactifs présents dans les catégories Archives
     * configurées (totaux distincts global). Sert au rendu de l'UI "Actions groupées".
     *
     * @return array{active:int,inactive:int,total:int}
     */
    protected function getArchivedProductCounts()
    {
        $ids = $this->getArchiveCategoryIds();
        if (empty($ids)) {
            return ['active' => 0, 'inactive' => 0, 'total' => 0];
        }
        $idsList = implode(',', $ids);
        // DISTINCT id_product à l'extérieur du GROUP BY pour éviter le double-count
        // d'un produit dans 2 catégories archives.
        $sql = 'SELECT p.active, COUNT(DISTINCT p.id_product) AS n
                FROM `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'category_product` cp
                    ON cp.id_product = p.id_product
                WHERE cp.id_category IN (' . $idsList . ')
                GROUP BY p.active';
        $rows = Db::getInstance()->executeS($sql);
        $out = ['active' => 0, 'inactive' => 0, 'total' => 0];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $n = (int) $r['n'];
                if ((int) $r['active'] === 1) {
                    $out['active'] = $n;
                } else {
                    $out['inactive'] = $n;
                }
                $out['total'] += $n;
            }
        }
        return $out;
    }

    /**
     * Compte les produits actifs / inactifs par catégorie Archives. Retourne
     * un tableau indexé par id_category avec name, active, inactive, total.
     * Utilisé pour l'UI "actions par catégorie".
     *
     * @return array<int, array{id:int,name:string,active:int,inactive:int,total:int}>
     */
    protected function getArchivedProductCountsPerCategory()
    {
        $ids = $this->getArchiveCategoryIds();
        if (empty($ids)) {
            return [];
        }
        $idsList = implode(',', $ids);
        $idLang = (int) $this->context->language->id;
        $sql = 'SELECT cp.id_category, p.active, COUNT(DISTINCT p.id_product) AS n
                FROM `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'category_product` cp
                    ON cp.id_product = p.id_product
                WHERE cp.id_category IN (' . $idsList . ')
                GROUP BY cp.id_category, p.active';
        $rows = Db::getInstance()->executeS($sql);

        // Récupère les noms des catégories en une requête.
        $names = [];
        $nameSql = 'SELECT id_category, name FROM `' . _DB_PREFIX_ . 'category_lang`
                    WHERE id_category IN (' . $idsList . ')
                      AND id_lang = ' . $idLang;
        $nameRows = Db::getInstance()->executeS($nameSql);
        if (is_array($nameRows)) {
            foreach ($nameRows as $nr) {
                $names[(int) $nr['id_category']] = (string) $nr['name'];
            }
        }

        // Init le tableau de sortie avec toutes les catégories (même celles
        // sans produits — affiche 0/0/0 plutôt que de les omettre).
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'id' => $id,
                'name' => $names[$id] ?? ('#' . $id),
                'active' => 0,
                'inactive' => 0,
                'total' => 0,
            ];
        }
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $catId = (int) $r['id_category'];
                $n = (int) $r['n'];
                if (!isset($out[$catId])) { continue; }
                if ((int) $r['active'] === 1) {
                    $out[$catId]['active'] = $n;
                } else {
                    $out[$catId]['inactive'] = $n;
                }
                $out[$catId]['total'] += $n;
            }
        }
        return $out;
    }

    /**
     * (Dés)active en masse les produits présents dans les catégories Archives.
     * Si $scopeCategoryId est fourni ET appartient à la config, n'opère que sur
     * cette catégorie. Sinon, applique à toutes les catégories archives.
     * Update à la fois `product` et `product_shop` (cohérent en multiboutique).
     *
     * @param int      $targetActive     0 pour désactiver, 1 pour réactiver
     * @param int|null $scopeCategoryId  Catégorie cible unique, ou null = toutes
     * @return int  nombre de produits effectivement modifiés
     */
    protected function bulkUpdateArchivedActive($targetActive, $scopeCategoryId = null)
    {
        $allIds = $this->getArchiveCategoryIds();
        if (empty($allIds)) {
            return 0;
        }
        // Filtrage de scope : on REFUSE silencieusement une catégorie qui ne
        // serait pas dans la config (sinon on offre à un attaquant la possibilité
        // de désactiver n'importe quoi en injectant un id_category arbitraire).
        if ($scopeCategoryId !== null && in_array((int) $scopeCategoryId, $allIds, true)) {
            $ids = [(int) $scopeCategoryId];
        } else {
            $ids = $allIds;
        }
        $targetActive = (int) $targetActive === 1 ? 1 : 0;
        $oppositeActive = $targetActive === 1 ? 0 : 1;
        $idsList = implode(',', $ids);
        $db = Db::getInstance();

        // 1) Table principale product : on ne touche que les lignes dont l'état
        //    diffère de la cible (évite les UPDATE inutiles + permet de retourner
        //    un compte exact des produits réellement modifiés).
        $sql = 'UPDATE `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'category_product` cp
                    ON cp.id_product = p.id_product
                SET p.active = ' . $targetActive . ', p.date_upd = "' . pSQL(date('Y-m-d H:i:s')) . '"
                WHERE cp.id_category IN (' . $idsList . ')
                  AND p.active = ' . $oppositeActive;
        $db->execute($sql);
        $affected = (int) $db->Affected_Rows();

        // 2) Table product_shop (multiboutique) : on aligne aussi.
        $sqlShop = 'UPDATE `' . _DB_PREFIX_ . 'product_shop` ps
                    INNER JOIN `' . _DB_PREFIX_ . 'category_product` cp
                        ON cp.id_product = ps.id_product
                    SET ps.active = ' . $targetActive
                    . (Configuration::get('PS_FORCE_FRIENDLY_PRODUCT') !== false ? '' : '')
                    . ' WHERE cp.id_category IN (' . $idsList . ')';
        $db->execute($sqlShop);

        // Purge les caches susceptibles d'afficher l'ancien état (front + admin).
        Product::flushPriceCache();
        if (method_exists('Tools', 'clearCache')) {
            try { Tools::clearCache(); } catch (Exception $e) { /* best-effort */ }
        }
        if (class_exists('Hook')) {
            try { Hook::exec('actionAdminProductsControllerSaveAfter'); } catch (Exception $e) { /* best-effort */ }
        }

        return $affected;
    }

    /**
     * Header standard MG Modules (zm40-ah). Pattern visuel commun à tous les
     * modules MG pour reconnaissance "marque de fabrique" — seule la couleur
     * dominante change par module.
     */
    protected function renderAdminHeader()
    {
        $shop = htmlspecialchars((string) Configuration::get('PS_SHOP_NAME'), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string) $this->displayName, ENT_QUOTES, 'UTF-8');
        $sub  = htmlspecialchars($this->l('Filtrer les produits des catégories Archives dans le BO'), ENT_QUOTES, 'UTF-8');
        $ver  = htmlspecialchars((string) $this->version, ENT_QUOTES, 'UTF-8');
        // Couleur ArchiveProducts : grape (palette ZM40 officielle, cf. catalog)
        $c1 = '#A855E0';
        $c2 = '#5b2780';
        return <<<HTML
<style>
.zm40-ah { background: linear-gradient(135deg, {$c1} 0%, {$c2} 100%); color: #fff; padding: 28px 32px; border-radius: 8px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.zm40-ah * { box-sizing: border-box; }
.zm40-ah h2 { margin: 0; font-size: 22px; font-weight: 600; color: #fff; line-height: 1.2; }
.zm40-ah-sub { opacity: 0.85; font-size: 13px; margin-top: 4px; color: #fff; }
.zm40-ah-badge { background: rgba(255,255,255,0.2); padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 500; color: #fff; white-space: nowrap; }
</style>
<div class="zm40-ah">
    <div>
        <h2>{$name}</h2>
        <div class="zm40-ah-sub">{$sub} &middot; v{$ver}</div>
    </div>
    <span class="zm40-ah-badge">{$shop}</span>
</div>
HTML;
    }

    /**
     * Notice de mise à jour (notify-only) — affichée si une version supérieure
     * est dispo sur GitHub. Fail-silent : aucune notice si réseau OFF.
     */
    protected function renderUpdateNotice()
    {
        $u = Zm40CommonAp::checkUpdate('archiveproducts', $this->version);
        if (!$u || empty($u['available'])) {
            return '';
        }
        $css = '<link rel="stylesheet" href="' . $this->_path . 'views/css/zm40-common.css">';
        $latest = htmlspecialchars((string) $u['latest'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars((string) $u['url'], ENT_QUOTES, 'UTF-8');
        $msg = htmlspecialchars(sprintf(
            $this->l('Une nouvelle version d\'Archive Products est disponible : %s.'),
            $latest
        ), ENT_QUOTES, 'UTF-8');
        $view = htmlspecialchars($this->l('Voir la release →'), ENT_QUOTES, 'UTF-8');
        return $css . '<div class="zm40-update-notice"><span>' . $msg . '</span>'
            . '<a href="' . $url . '" target="_blank" rel="noopener">' . $view . '</a></div>';
    }

    /**
     * Panneau « À propos » — module open source GPL v3, signé ZM40 / Magic Garden.
     */
    protected function renderAboutPanel()
    {
        $name = htmlspecialchars((string) $this->displayName, ENT_QUOTES, 'UTF-8');
        $ver = htmlspecialchars((string) $this->version, ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars(Zm40CommonAp::siteUrl('archiveproducts', 'about'), ENT_QUOTES, 'UTF-8');
        $github = htmlspecialchars(Zm40CommonAp::githubUrl('archiveproducts'), ENT_QUOTES, 'UTF-8');
        $copy = htmlspecialchars($this->l('Module libre & open source — GPL v3'), ENT_QUOTES, 'UTF-8');
        return '<div class="panel zm40-about">'
            . '<div class="panel-heading"><i class="icon-info-circle"></i> ' . $copy . '</div>'
            . '<p style="margin:6px 0 4px;">'
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' v' . $ver . ' — '
            . $this->l('édité par') . ' '
            . '<a href="' . $url . '" target="_blank" rel="noopener">ZM40 / Magic Garden</a>. '
            . $this->l('Le code source est disponible et modifiable')
            . ' (<a href="' . $github . '" target="_blank" rel="noopener">GitHub</a>).'
            . '</p></div>';
    }

    /**
     * Footer d'attribution standard ZM40.
     */
    protected function renderFooter()
    {
        return Zm40CommonAp::footer($this->displayName, $this->version, 'archiveproducts');
    }

    /**
     * Bloc « Écosystème ZM40 » — feed des autres modules ZM40 disponibles.
     * Le CSS .zm40-eco-* (grid) est chargé ici pour garantir le layout même
     * sans notice de mise à jour active.
     */
    protected function renderEcosystem()
    {
        $modules = Zm40CommonAp::modulesFeed('archiveproducts');
        if (empty($modules)) {
            return '';
        }
        $this->context->smarty->assign(array('zm40_modules' => $modules));
        $css = '<link rel="stylesheet" href="' . $this->_path . 'views/css/zm40-common.css">';
        return $css . $this->display(__FILE__, 'views/templates/admin/_partials/zm40_modules.tpl');
    }

    /**
     * Génère le panneau de configuration avec arbre de catégories + liste sélectionnée
     */
    protected function renderConfigPanel()
    {
        $idLang = (int) $this->context->language->id;

        // Construction de l'arbre de catégories
        $tree = Category::getNestedCategories(null, $idLang, true);

        // Catégories actuellement sélectionnées
        $stored = Configuration::get('ARCHIVEPRODUCTS_CATEGORIES');
        $selectedIds = $stored ? array_map('intval', array_filter(explode(',', $stored))) : [];

        // Map id => nom complet (avec chemin) pour l'affichage de la liste latérale
        $allCategories = Category::getCategories($idLang, true, false);
        $catMap = [];
        foreach ($allCategories as $cat) {
            $catMap[(int) $cat['id_category']] = [
                'id' => (int) $cat['id_category'],
                'name' => $cat['name'],
                'depth' => (int) $cat['level_depth'],
            ];
        }

        $formAction = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;

        $treeHtml = $this->renderCategoryTree($tree, $selectedIds);

        // Préparer les valeurs textuelles
        $title = $this->l('Configuration des catégories Archives');
        $desc = $this->l('Cochez une ou plusieurs catégories dont les produits seront masqués lors du filtrage par nom dans le listing produit.');
        $labelTree = $this->l('Arborescence des catégories');
        $labelSelected = $this->l('Catégories sélectionnées');
        $btnSave = $this->l('Sauvegarder');
        $btnExpand = $this->l('Tout déplier');
        $btnCollapse = $this->l('Tout replier');
        $btnClear = $this->l('Tout désélectionner');
        $searchPlaceholder = $this->l('Rechercher une catégorie…');
        $emptyMsg = $this->l('Aucune catégorie sélectionnée.');
        $countLabel = $this->l('catégorie(s)');

        $token = Tools::getAdminTokenLite('AdminModules');

        $count = count($selectedIds);

        ob_start();
        ?>
        <style>
            .archiveproducts-panel { display: flex; gap: 20px; flex-wrap: wrap; }
            .archiveproducts-panel .ap-card { background: #fff; border: 1px solid #e0e6ed; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
            .archiveproducts-panel .ap-card-header { padding: 12px 16px; background: #f6f9fc; border-bottom: 1px solid #e0e6ed; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
            .archiveproducts-panel .ap-card-body { padding: 16px; }
            .archiveproducts-panel .ap-tree-col { flex: 1 1 100%; min-width: 100%; }
            .archiveproducts-panel .ap-toolbar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
            .archiveproducts-panel .ap-search { flex: 1 1 200px; }
            .archiveproducts-panel .ap-tree-wrap { max-height: 520px; overflow: auto; border: 1px solid #e0e6ed; border-radius: 3px; padding: 8px 12px; background: #fafbfc; }
            .archiveproducts-panel ul.ap-tree { list-style: none; padding-left: 0; margin: 0; }
            .archiveproducts-panel ul.ap-tree ul { list-style: none; padding-left: 22px; margin: 0; border-left: 1px dashed #d0d7de; margin-left: 9px; }
            .archiveproducts-panel ul.ap-tree li { padding: 3px 0; }
            .archiveproducts-panel .ap-node { display: flex; align-items: center; gap: 6px; }
            .archiveproducts-panel .ap-toggle { width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: #6c7a89; user-select: none; font-size: 12px; }
            .archiveproducts-panel .ap-toggle.ap-empty { visibility: hidden; }
            .archiveproducts-panel .ap-label { cursor: pointer; padding: 2px 6px; border-radius: 3px; flex: 1; }
            .archiveproducts-panel .ap-label:hover { background: #eef3f8; }
            .archiveproducts-panel .ap-label.ap-checked { background: #f3e8ff; color: #7c2dba; font-weight: 500; }
            .archiveproducts-panel .ap-id { color: #9aa5b1; font-size: 11px; margin-left: 6px; }
            .archiveproducts-panel li.ap-collapsed > ul { display: none; }
            .archiveproducts-panel li.ap-hidden { display: none; }
            .archiveproducts-panel .ap-selected-list { list-style: none; padding: 0; margin: 0; max-height: 480px; overflow: auto; }
            .archiveproducts-panel .ap-selected-list li { display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; border-bottom: 1px solid #f0f3f6; gap: 8px; }
            .archiveproducts-panel .ap-selected-list li:last-child { border-bottom: none; }
            .archiveproducts-panel .ap-selected-list .ap-cat-name { flex: 1; word-break: break-word; }
            .archiveproducts-panel .ap-selected-list .ap-cat-path { color: #8b95a1; font-size: 11px; display: block; }
            .archiveproducts-panel .ap-remove-btn { background: transparent; border: none; color: #c0392b; cursor: pointer; font-size: 14px; padding: 4px 8px; border-radius: 3px; }
            .archiveproducts-panel .ap-remove-btn:hover { background: #fde7e4; }
            .archiveproducts-panel .ap-empty-msg { color: #8b95a1; font-style: italic; text-align: center; padding: 20px; }
            .archiveproducts-panel .ap-counter { background: #25b9d7; color: #fff; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
            .archiveproducts-panel .ap-counter.ap-zero { background: #b0bac4; }
            .archiveproducts-panel .ap-footer { margin-top: 18px; text-align: right; }
            .archiveproducts-panel mark { background: #fff59d; padding: 0 2px; border-radius: 2px; }
            /* Bloc "Comment ça fonctionne" — pédagogie pour les nouveaux utilisateurs */
            .ap-howto { background: #f3e8ff; border: 1px solid #d8b9f5; border-left: 4px solid #A855E0; border-radius: 6px; padding: 14px 18px; margin-bottom: 18px; color: #3b1d63; }
            .ap-howto h4 { margin: 0 0 8px; font-size: 14px; font-weight: 700; color: #5b2780; display: flex; align-items: center; gap: 6px; }
            .ap-howto ul { margin: 4px 0 0; padding-left: 18px; line-height: 1.5; font-size: 13px; }
            .ap-howto ul li { margin-bottom: 4px; }
            .ap-howto ul li:last-child { margin-bottom: 0; }
            .ap-howto strong { color: #5b2780; }
            .ap-howto code { background: rgba(168, 85, 224, 0.12); padding: 1px 6px; border-radius: 3px; font-size: 12px; color: #5b2780; }
            .ap-scope { background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; padding: 10px 14px; margin-bottom: 18px; color: #6d4c00; font-size: 13px; line-height: 1.5; display: flex; align-items: flex-start; gap: 10px; }
            .ap-scope i { color: #b35900; flex-shrink: 0; margin-top: 1px; }
            .ap-scope strong { color: #6d4c00; }
            /* Bloc "Actions groupées" — mise hors-ligne en masse des archivés */
            .ap-bulk { background: #fff; border: 1px solid #e3e5e7; border-left: 4px solid #6c757d; border-radius: 6px; padding: 14px 18px; margin-bottom: 18px; }
            .ap-bulk h4 { margin: 0 0 4px; font-size: 14px; font-weight: 700; color: #202223; display: flex; align-items: center; gap: 6px; }
            .ap-bulk .ap-bulk-sub { font-size: 12.5px; color: #6d7175; margin-bottom: 12px; line-height: 1.5; }
            .ap-bulk-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            .ap-bulk-table th { padding: 8px 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: #6d7175; text-align: left; background: #f8f9fa; border-bottom: 1px solid #e3e5e7; }
            .ap-bulk-table td { padding: 9px 10px; font-size: 13px; border-bottom: 1px solid #f0f3f6; }
            .ap-bulk-table tr:last-child td { border-bottom: none; }
            .ap-bulk-table th.num, .ap-bulk-table td.num { text-align: center; width: 70px; font-variant-numeric: tabular-nums; }
            .ap-bulk-table th.acts, .ap-bulk-table td.acts { text-align: right; width: 150px; }
            .ap-bulk-table td.num.act { color: #28a745; font-weight: 600; }
            .ap-bulk-table td.num.ina { color: #dc3545; font-weight: 600; }
            .ap-bulk-table .ap-cat-id { color: #9aa5b1; font-size: 11px; margin-left: 6px; font-weight: 400; }
            .ap-bulk-table td.acts form { display: inline-flex; gap: 4px; margin: 0; }
            .ap-bulk-actions { display: inline-flex; gap: 8px; flex-wrap: wrap; }
            .ap-bulk-actions button { padding: 7px 14px; font-size: 13px; font-weight: 500; border-radius: 4px; cursor: pointer; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 6px; }
            .ap-bulk-actions button:disabled { opacity: 0.5; cursor: not-allowed; }
            .ap-bulk .ap-btn-off { background: #dc3545; color: #fff; border-color: #dc3545; padding: 7px 14px; font-size: 13px; font-weight: 500; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
            .ap-bulk .ap-btn-off:hover:not(:disabled) { background: #c82333; }
            .ap-bulk .ap-btn-on { background: #28a745; color: #fff; border-color: #28a745; padding: 7px 14px; font-size: 13px; font-weight: 500; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
            .ap-bulk .ap-btn-on:hover:not(:disabled) { background: #218838; }
            .ap-bulk .ap-btn-off:disabled, .ap-bulk .ap-btn-on:disabled { opacity: 0.45; cursor: not-allowed; }
            .ap-bulk .ap-btn-sm { padding: 5px 10px; font-size: 12px; border: 1px solid; }
            .ap-bulk .ap-btn-remove { background: transparent; color: #6c757d; border: 1px solid #cbd5e1; padding: 5px 10px; font-size: 12px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
            .ap-bulk .ap-btn-remove:hover { background: #f1f3f5; color: #dc3545; border-color: #f5a8a8; }
            .ap-bulk-global { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 12px 14px; background: #f8f9fa; border-radius: 5px; border: 1px solid #e3e5e7; margin-top: 14px; flex-wrap: wrap; }
            .ap-bulk-global > div:first-child { font-size: 13px; color: #495057; }
            .ap-bulk-global-counts { color: #6d7175; margin-left: 8px; }

            /* Modal de confirmation pour actions destructives ou massives */
            .ap-modal-backdrop {
                position: fixed; inset: 0;
                background: rgba(15, 23, 42, 0.55);
                display: flex; align-items: center; justify-content: center;
                z-index: 10050;
                animation: apFadeIn .15s ease;
                padding: 16px;
            }
            @keyframes apFadeIn { from { opacity: 0; } to { opacity: 1; } }
            .ap-modal-box {
                background: #fff; border-radius: 10px;
                max-width: 480px; width: 100%;
                box-shadow: 0 20px 60px rgba(0,0,0,0.25);
                padding: 24px 24px 18px;
                animation: apSlideIn .18s ease;
            }
            @keyframes apSlideIn { from { transform: translateY(-12px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
            .ap-modal-icon {
                display: inline-flex; align-items: center; justify-content: center;
                width: 44px; height: 44px; border-radius: 50%;
                margin-bottom: 12px;
                font-size: 22px;
            }
            .ap-modal-icon.danger { background: #fef2f2; color: #dc2626; }
            .ap-modal-icon.warning { background: #fef3c7; color: #d97706; }
            .ap-modal-icon.success { background: #f0fdf4; color: #16a34a; }
            .ap-modal-title {
                font-size: 17px; font-weight: 700; color: #111827;
                margin: 0 0 8px;
            }
            .ap-modal-body {
                font-size: 13.5px; line-height: 1.55; color: #475569;
                margin-bottom: 20px;
            }
            .ap-modal-actions {
                display: flex; justify-content: flex-end; gap: 8px;
                flex-wrap: wrap;
            }
            .ap-modal-cancel {
                padding: 9px 18px; font-size: 13px; font-weight: 600;
                background: transparent; color: #475569;
                border: 1px solid #cbd5e1; border-radius: 6px;
                cursor: pointer;
            }
            .ap-modal-cancel:hover { background: #f1f5f9; color: #1e293b; }
            .ap-modal-confirm {
                padding: 9px 18px; font-size: 13px; font-weight: 600;
                color: #fff; border: 1px solid transparent; border-radius: 6px;
                cursor: pointer;
            }
            .ap-modal-confirm.danger { background: #dc2626; border-color: #dc2626; }
            .ap-modal-confirm.danger:hover { background: #b91c1c; }
            .ap-modal-confirm.success { background: #16a34a; border-color: #16a34a; }
            .ap-modal-confirm.success:hover { background: #15803d; }
            .ap-modal-confirm.warning { background: #d97706; border-color: #d97706; }
            .ap-modal-confirm.warning:hover { background: #b45309; }
        </style>

        <form method="post" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" id="archiveproducts-form">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="submitArchiveProducts" value="1">

            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-archive"></i> <?php echo $title; ?>
                </div>
                <div class="panel-body">
                    <div class="ap-scope">
                        <i class="icon-info-circle"></i>
                        <div>
                            <strong><?php echo $this->l('Ce module agit UNIQUEMENT sur le listing du Back Office.'); ?></strong>
                            <?php echo $this->l('Vos clients sur la boutique ne voient aucun changement : les produits archivés restent commandables s\'ils ont une URL active. Pour les masquer côté boutique, utilisez les actions groupées ci-dessous ou la désactivation native PrestaShop.'); ?>
                        </div>
                    </div>

                    <?php
                    $perCat = $this->getArchivedProductCountsPerCategory();
                    $totals = $this->getArchivedProductCounts();
                    if (!empty($perCat)):
                    ?>
                    <div class="ap-bulk">
                        <h4><i class="icon-power-off"></i> <?php echo $this->l('Actions groupées sur les produits archivés'); ?></h4>
                        <p class="ap-bulk-sub">
                            <?php echo $this->l('Désactive (ou réactive) en une fois les produits d\'une catégorie Archives donnée. Désactivés, ils disparaissent aussi de la boutique côté client (statut PrestaShop natif).'); ?>
                        </p>

                        <table class="ap-bulk-table">
                            <thead>
                                <tr>
                                    <th><?php echo $this->l('Catégorie'); ?></th>
                                    <th class="num"><?php echo $this->l('Actifs'); ?></th>
                                    <th class="num"><?php echo $this->l('Inactifs'); ?></th>
                                    <th class="num"><?php echo $this->l('Total'); ?></th>
                                    <th class="acts"><?php echo $this->l('Actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($perCat as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <span class="ap-cat-id">#<?php echo (int) $row['id']; ?></span>
                                    </td>
                                    <td class="num act"><?php echo (int) $row['active']; ?></td>
                                    <td class="num ina"><?php echo (int) $row['inactive']; ?></td>
                                    <td class="num"><?php echo (int) $row['total']; ?></td>
                                    <td class="acts">
                                        <form method="post" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" data-arp-confirm-form="1">
                                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="arp_target_category" value="<?php echo (int) $row['id']; ?>">
                                            <button type="submit" name="arp_bulk_deactivate" value="1" class="ap-btn-off ap-btn-sm" data-confirm="<?php echo htmlspecialchars(sprintf($this->l('Désactiver les %1$d produits actifs de la catégorie « %2$s » ? Ils disparaîtront de la boutique côté client. Action réversible.'), $row['active'], $row['name']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $row['active'] === 0 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars($this->l('Désactiver les produits'), ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="icon-eye-slash"></i>
                                            </button>
                                            <button type="submit" name="arp_bulk_reactivate" value="1" class="ap-btn-on ap-btn-sm" data-confirm="<?php echo htmlspecialchars(sprintf($this->l('Réactiver les %1$d produits inactifs de la catégorie « %2$s » ? Ils redeviendront visibles sur la boutique côté client.'), $row['inactive'], $row['name']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $row['inactive'] === 0 ? 'disabled' : ''; ?> title="<?php echo htmlspecialchars($this->l('Réactiver les produits'), ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="icon-eye"></i>
                                            </button>
                                            <button type="submit" name="arp_remove_category" value="1" class="ap-btn-remove ap-btn-sm" data-confirm="<?php echo htmlspecialchars(sprintf($this->l('Retirer la catégorie « %s » de la liste Archives ? Les produits ne sont PAS affectés — ils gardent leur catégorie et leur état actif/inactif. Le filtre du listing BO ne s\'applique simplement plus à cette catégorie.'), $row['name']), ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($this->l('Retirer cette catégorie de la liste Archives'), ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="icon-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if (count($perCat) > 1 && $totals['total'] > 0): ?>
                        <div class="ap-bulk-global">
                            <div>
                                <strong><?php echo $this->l('Sur toutes les catégories archives :'); ?></strong>
                                <span class="ap-bulk-global-counts"><?php echo sprintf($this->l('%1$d actifs · %2$d inactifs · %3$d total'), (int) $totals['active'], (int) $totals['inactive'], (int) $totals['total']); ?></span>
                            </div>
                            <form method="post" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" class="ap-bulk-actions" data-arp-confirm-form="1">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" name="arp_bulk_deactivate" value="1" class="ap-btn-off" data-confirm="<?php echo htmlspecialchars(sprintf($this->l('Désactiver les %d produit(s) actifs sur TOUTES les catégories Archives ? Action réversible.'), $totals['active']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $totals['active'] === 0 ? 'disabled' : ''; ?>>
                                    <i class="icon-eye-slash"></i> <?php echo sprintf($this->l('Tout désactiver (%d)'), (int) $totals['active']); ?>
                                </button>
                                <button type="submit" name="arp_bulk_reactivate" value="1" class="ap-btn-on" data-confirm="<?php echo htmlspecialchars(sprintf($this->l('Réactiver les %d produit(s) inactifs sur TOUTES les catégories Archives ?'), $totals['inactive']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo $totals['inactive'] === 0 ? 'disabled' : ''; ?>>
                                    <i class="icon-eye"></i> <?php echo sprintf($this->l('Tout réactiver (%d)'), (int) $totals['inactive']); ?>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>

                        <script>
                        // Modal de confirmation custom — bloque les clics compulsifs.
                        // Stratégie : on attache un click handler sur CHAQUE bouton
                        // qui a data-confirm, plutôt qu'un onsubmit/delegation
                        // (plus fiable cross-browser, pas de piège submitter/activeElement).
                        (function() {
                            console.log('[ArchiveProducts] modal script loaded');

                            function showModal(opts) {
                                var variant = opts.variant || 'warning';
                                var iconChar = { danger: '⚠', warning: '⚠', success: '✓' }[variant] || 'i';
                                var backdrop = document.createElement('div');
                                backdrop.className = 'ap-modal-backdrop';
                                backdrop.setAttribute('role', 'dialog');
                                backdrop.setAttribute('aria-modal', 'true');
                                backdrop.innerHTML =
                                    '<div class="ap-modal-box">' +
                                        '<div class="ap-modal-icon ' + variant + '">' + iconChar + '</div>' +
                                        '<h3 class="ap-modal-title"></h3>' +
                                        '<div class="ap-modal-body"></div>' +
                                        '<div class="ap-modal-actions">' +
                                            '<button type="button" class="ap-modal-cancel"></button>' +
                                            '<button type="button" class="ap-modal-confirm ' + variant + '"></button>' +
                                        '</div>' +
                                    '</div>';
                                document.body.appendChild(backdrop);
                                backdrop.querySelector('.ap-modal-title').textContent = opts.title || 'Confirmation';
                                backdrop.querySelector('.ap-modal-body').textContent = opts.body || '';
                                backdrop.querySelector('.ap-modal-cancel').textContent = opts.cancelLabel || 'Annuler';
                                backdrop.querySelector('.ap-modal-confirm').textContent = opts.confirmLabel || 'Confirmer';

                                function close() {
                                    backdrop.remove();
                                    document.removeEventListener('keydown', onKey);
                                }
                                function onKey(e) {
                                    if (e.key === 'Escape') { close(); }
                                }
                                function doConfirm() {
                                    close();
                                    if (opts.onConfirm) { opts.onConfirm(); }
                                }
                                backdrop.querySelector('.ap-modal-cancel').addEventListener('click', close);
                                backdrop.querySelector('.ap-modal-confirm').addEventListener('click', doConfirm);
                                backdrop.addEventListener('click', function(e) { if (e.target === backdrop) close(); });
                                document.addEventListener('keydown', onKey);
                                // Focus initial sur Annuler (anti-Entrée réflexe).
                                setTimeout(function() { backdrop.querySelector('.ap-modal-cancel').focus(); }, 50);
                            }
                            window.arpShowModal = showModal;

                            function attachToButton(btn) {
                                if (btn.dataset.arpHandlerAttached === '1') { return; }
                                btn.dataset.arpHandlerAttached = '1';
                                btn.addEventListener('click', function(e) {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    var form = btn.closest('form');
                                    if (!form) { console.warn('[ArchiveProducts] form introuvable pour', btn); return; }

                                    var variant = 'warning';
                                    if (btn.classList.contains('ap-btn-off') || btn.classList.contains('ap-btn-remove')) { variant = 'danger'; }
                                    else if (btn.classList.contains('ap-btn-on')) { variant = 'success'; }

                                    var title = btn.getAttribute('title') || 'Confirmer cette action';
                                    showModal({
                                        title: title,
                                        body: btn.getAttribute('data-confirm'),
                                        variant: variant,
                                        confirmLabel: title,
                                        onConfirm: function() {
                                            // Duplique nom/valeur du bouton dans un hidden :
                                            // form.submit() ne propage pas le bouton cliqué.
                                            var inp = document.createElement('input');
                                            inp.type = 'hidden';
                                            inp.name = btn.getAttribute('name');
                                            inp.value = btn.getAttribute('value') || '1';
                                            form.appendChild(inp);
                                            form.submit();
                                        }
                                    });
                                });
                            }

                            function bindAll() {
                                var buttons = document.querySelectorAll('[data-arp-confirm-form] button[data-confirm]');
                                console.log('[ArchiveProducts] modal bind: ' + buttons.length + ' boutons trouvés');
                                buttons.forEach(attachToButton);
                            }

                            // Bind immédiatement (le script est rendu APRÈS les boutons en source order)
                            // + au DOMContentLoaded au cas où.
                            bindAll();
                            if (document.readyState === 'loading') {
                                document.addEventListener('DOMContentLoaded', bindAll);
                            }
                        })();
                        </script>
                    </div>
                    <?php endif; ?>

                    <div class="ap-howto">
                        <h4><i class="icon-lightbulb-o"></i> <?php echo $this->l('Comment ça fonctionne'); ?></h4>
                        <ul>
                            <li><?php echo $this->l('Cochez ci-dessous la (ou les) catégorie(s) qui servira de "tag Archives" — par exemple créez une catégorie spécifique nommée'); ?> <code>Archives</code>.</li>
                            <li><?php echo $this->l('Pour archiver un produit : éditez-le, onglet Associations, et ajoutez-le à la catégorie Archives sélectionnée.'); ?> <strong><?php echo $this->l('Vous pouvez conserver ses autres catégories'); ?></strong> — <?php echo $this->l('le module masque dès qu\'un produit appartient à AU MOINS UNE catégorie Archives.'); ?></li>
                            <li><?php echo $this->l('Au-dessus du listing produit du BO, un toggle 3 états apparaît :'); ?> <strong><?php echo $this->l('Masquer'); ?></strong> (<?php echo $this->l('défaut, votre vue quotidienne'); ?>), <strong><?php echo $this->l('Tout afficher'); ?></strong>, <strong><?php echo $this->l('Archives seules'); ?></strong> (<?php echo $this->l('pour faire le tri / réactiver'); ?>).</li>
                            <li><?php echo $this->l('Pour désarchiver : retirez la catégorie Archives du produit. Il réapparaît immédiatement.'); ?></li>
                        </ul>
                    </div>

                    <div class="archiveproducts-panel">
                        <!-- Colonne arbre -->
                        <div class="ap-card ap-tree-col">
                            <div class="ap-card-header">
                                <span><i class="icon-sitemap"></i> <?php echo $labelTree; ?></span>
                            </div>
                            <div class="ap-card-body">
                                <div class="ap-toolbar">
                                    <input type="text" class="form-control ap-search" id="ap-search" placeholder="<?php echo htmlspecialchars($searchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="button" class="btn btn-default btn-sm" id="ap-expand-all"><i class="icon-plus-square-o"></i> <?php echo $btnExpand; ?></button>
                                    <button type="button" class="btn btn-default btn-sm" id="ap-collapse-all"><i class="icon-minus-square-o"></i> <?php echo $btnCollapse; ?></button>
                                </div>
                                <div class="ap-tree-wrap">
                                    <?php echo $treeHtml; ?>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="ap-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="icon-save"></i> <?php echo $btnSave; ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <script>
        (function() {
            var emptyMsg = <?php echo json_encode($emptyMsg); ?>;
            var countLabel = <?php echo json_encode($countLabel); ?>;
            var catData = <?php echo json_encode($this->buildCategoryDataMap($allCategories), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

            var $form = document.getElementById('archiveproducts-form');
            var $tree = document.querySelector('.ap-tree-wrap');
            var $search = document.getElementById('ap-search');

            function getSelectedIds() {
                var ids = [];
                var checks = $tree.querySelectorAll('input.ap-check:checked');
                checks.forEach(function(c) { ids.push(parseInt(c.value, 10)); });
                return ids;
            }

            function escapeHtml(str) {
                return String(str).replace(/[&<>"']/g, function(m) {
                    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
                });
            }

            function buildPath(id) {
                var parts = [];
                var current = catData[id];
                var guard = 0;
                while (current && guard < 50) {
                    parts.unshift(current.name);
                    if (!current.parent || !catData[current.parent]) break;
                    current = catData[current.parent];
                    guard++;
                }
                return parts.join(' › ');
            }

            function renderSelectedList() {
                // Sidebar "Catégories sélectionnées" retirée (redondante avec la
                // table d'actions groupées du haut). Conservée comme no-op pour
                // garder le pattern d'appel et permettre une réintroduction
                // ultérieure si besoin.
            }

            function syncLabelsAndHidden() {
                // Mettre en évidence les labels cochés
                $tree.querySelectorAll('input.ap-check').forEach(function(c) {
                    var label = c.closest('.ap-node').querySelector('.ap-label');
                    if (label) {
                        label.classList.toggle('ap-checked', c.checked);
                    }
                });
                // Synchroniser les inputs cachés du formulaire
                var oldHidden = $form.querySelectorAll('input.ap-hidden-cat');
                oldHidden.forEach(function(h) { h.remove(); });
                getSelectedIds().forEach(function(id) {
                    var h = document.createElement('input');
                    h.type = 'hidden';
                    h.name = 'ARCHIVEPRODUCTS_CATEGORIES[]';
                    h.value = id;
                    h.className = 'ap-hidden-cat';
                    $form.appendChild(h);
                });
            }

            function refresh() {
                syncLabelsAndHidden();
                renderSelectedList();
            }

            // Toggle expand/collapse au clic sur la flèche
            $tree.addEventListener('click', function(e) {
                if (e.target.classList.contains('ap-toggle') && !e.target.classList.contains('ap-empty')) {
                    var li = e.target.closest('li');
                    if (li) {
                        li.classList.toggle('ap-collapsed');
                        e.target.textContent = li.classList.contains('ap-collapsed') ? '▶' : '▼';
                    }
                }
            });

            // Clic sur le label = toggle de la checkbox
            $tree.addEventListener('click', function(e) {
                if (e.target.classList.contains('ap-label')) {
                    var node = e.target.closest('.ap-node');
                    var check = node ? node.querySelector('input.ap-check') : null;
                    if (check) {
                        check.checked = !check.checked;
                        refresh();
                    }
                }
            });

            // Changement direct sur la checkbox
            $tree.addEventListener('change', function(e) {
                if (e.target.classList.contains('ap-check')) {
                    refresh();
                }
            });

            // (Sidebar "Catégories sélectionnées" retirée → listeners obsolètes
            // supprimés. Le retrait d'une catégorie passe désormais par le
            // bouton 🗑 du tableau "Actions groupées" en haut de la page.)

            // Tout déplier / replier
            document.getElementById('ap-expand-all').addEventListener('click', function() {
                $tree.querySelectorAll('li.ap-collapsed').forEach(function(li) {
                    li.classList.remove('ap-collapsed');
                    var t = li.querySelector(':scope > .ap-node > .ap-toggle');
                    if (t && !t.classList.contains('ap-empty')) t.textContent = '▼';
                });
            });
            document.getElementById('ap-collapse-all').addEventListener('click', function() {
                $tree.querySelectorAll('li').forEach(function(li) {
                    if (li.querySelector(':scope > ul')) {
                        li.classList.add('ap-collapsed');
                        var t = li.querySelector(':scope > .ap-node > .ap-toggle');
                        if (t && !t.classList.contains('ap-empty')) t.textContent = '▶';
                    }
                });
            });

            // Recherche dans l'arbre
            $search.addEventListener('input', function() {
                var q = $search.value.trim().toLowerCase();
                var allLi = $tree.querySelectorAll('li');
                // Reset
                allLi.forEach(function(li) {
                    li.classList.remove('ap-hidden');
                    var label = li.querySelector(':scope > .ap-node > .ap-label .ap-text');
                    if (label) {
                        label.innerHTML = escapeHtml(label.textContent);
                    }
                });
                if (!q) return;
                // Cacher tous, puis afficher ceux qui matchent + leurs ancêtres + descendants
                allLi.forEach(function(li) { li.classList.add('ap-hidden'); });
                allLi.forEach(function(li) {
                    var label = li.querySelector(':scope > .ap-node > .ap-label .ap-text');
                    if (!label) return;
                    var text = label.textContent.toLowerCase();
                    if (text.indexOf(q) !== -1) {
                        // Highlight
                        var rx = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
                        label.innerHTML = escapeHtml(label.textContent).replace(rx, '<mark>$1</mark>');
                        // Afficher ce li et tous ses ancêtres
                        var node = li;
                        while (node && node !== $tree) {
                            if (node.tagName === 'LI') {
                                node.classList.remove('ap-hidden');
                                node.classList.remove('ap-collapsed');
                                var t = node.querySelector(':scope > .ap-node > .ap-toggle');
                                if (t && !t.classList.contains('ap-empty')) t.textContent = '▼';
                            }
                            node = node.parentElement;
                        }
                    }
                });
            });

            // Init
            refresh();
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Génère récursivement le HTML de l'arbre des catégories
     */
    protected function renderCategoryTree(array $nodes, array $selectedIds, $depth = 0)
    {
        if (empty($nodes)) {
            return '';
        }

        $html = '<ul class="ap-tree">';
        foreach ($nodes as $node) {
            $id = (int) $node['id_category'];
            $name = $node['name'];
            $hasChildren = !empty($node['children']);
            $checked = in_array($id, $selectedIds, true);
            // Replier par défaut au-delà du niveau 2 pour ne pas saturer
            $collapsed = $depth >= 2 && $hasChildren ? ' ap-collapsed' : '';

            $html .= '<li class="' . trim($collapsed) . '">';
            $html .= '<div class="ap-node">';
            if ($hasChildren) {
                $arrow = $collapsed ? '▶' : '▼';
                $html .= '<span class="ap-toggle">' . $arrow . '</span>';
            } else {
                $html .= '<span class="ap-toggle ap-empty">•</span>';
            }
            $html .= '<label class="ap-label' . ($checked ? ' ap-checked' : '') . '">';
            $html .= '<input type="checkbox" class="ap-check" value="' . $id . '"' . ($checked ? ' checked' : '') . '> ';
            $html .= '<span class="ap-text">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>';
            $html .= '<span class="ap-id">#' . $id . '</span>';
            $html .= '</label>';
            $html .= '</div>';

            if ($hasChildren) {
                $html .= $this->renderCategoryTree($node['children'], $selectedIds, $depth + 1);
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /**
     * Construit une map id => {name, parent} pour le JS (calcul du chemin)
     */
    protected function buildCategoryDataMap(array $allCategories)
    {
        $map = [];
        foreach ($allCategories as $cat) {
            $map[(int) $cat['id_category']] = [
                'name' => $cat['name'],
                'parent' => (int) $cat['id_parent'],
            ];
        }
        return $map;
    }

    /**
     * Récupère la liste des IDs de catégories archives configurées
     */
    protected function getArchiveCategoryIds()
    {
        $stored = Configuration::get('ARCHIVEPRODUCTS_CATEGORIES');
        if (empty($stored)) {
            return [];
        }
        $ids = array_map('intval', explode(',', $stored));
        return array_values(array_filter($ids));
    }

    /**
     * Détermine le mode actif (depuis l'URL) : 0 (masquer, défaut), 1 (tout), 2 (archives seules)
     */
    protected function getShowArchivesMode()
    {
        $mode = Tools::getValue('ap_show_archives', '0');
        if (!in_array((string) $mode, ['0', '1', '2'], true)) {
            $mode = '0';
        }
        return (string) $mode;
    }

    /**
     * Hook PS 8.x - Injecte le toggle "Produits archivés" au-dessus de la grille produit
     * (via JS, sans modifier la structure de la grille ni ajouter de colonne)
     */
    public function hookDisplayBackOfficeHeader()
    {
        $categoryIds = $this->getArchiveCategoryIds();
        if (empty($categoryIds)) {
            return '';
        }

        // Détection de la page : listing produits Symfony (route admin_products_index)
        $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $path = $request->getPathInfo();
        // L'URL ressemble à /{admin_folder}/index.php/sell/catalog/products/
        if (strpos($path, '/sell/catalog/products') === false) {
            return '';
        }
        // Exclure les pages de création/édition produit
        if (preg_match('#/sell/catalog/products/(new|\d+|create|edit)#', $path)) {
            return '';
        }

        $currentMode = $this->getShowArchivesMode();

        $labelTitle = $this->l('Produits archivés');
        $labelHide = $this->l('Masquer');
        $labelAll = $this->l('Tout afficher');
        $labelOnly = $this->l('Archives seules');

        // JSON-encode pour injection sûre dans le JS
        $i18n = json_encode([
            'title' => $labelTitle,
            'hide' => $labelHide,
            'all' => $labelAll,
            'only' => $labelOnly,
            'current' => $currentMode,
            'paramName' => 'ap_show_archives',
        ]);

        $html = '<style>
            .ap-toggle-bar {
                display: inline-flex;
                align-items: center;
                gap: 12px;
                padding: 8px 14px;
                background: #fff;
                border: 1px solid #e0e6ed;
                border-radius: 4px;
                box-shadow: 0 1px 2px rgba(0,0,0,.04);
                margin: 0 0 12px 0;
                font-size: 13px;
            }
            .ap-toggle-bar .ap-toggle-label {
                font-weight: 600;
                color: #363a41;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }
            .ap-toggle-bar .ap-toggle-label i {
                color: #A855E0;
            }
            .ap-toggle-group {
                display: inline-flex;
                border: 1px solid #ced4da;
                border-radius: 4px;
                overflow: hidden;
            }
            .ap-toggle-group button {
                border: none;
                background: #fff;
                color: #495057;
                padding: 6px 14px;
                font-size: 12px;
                font-weight: 500;
                cursor: pointer;
                border-right: 1px solid #ced4da;
                transition: background .15s, color .15s;
                display: inline-flex;
                align-items: center;
                gap: 5px;
            }
            .ap-toggle-group button:last-child { border-right: none; }
            .ap-toggle-group button:hover { background: #eef3f8; }
            .ap-toggle-group button.ap-active {
                background: #25b9d7;
                color: #fff;
            }
            .ap-toggle-group button.ap-active.ap-mode-2 { background: #A855E0; }
            .ap-toggle-group button.ap-active.ap-mode-1 { background: #6c757d; }
            .ap-toggle-group button.ap-active.ap-mode-0 { background: #25b9d7; }
        </style>
        <script>
        (function() {
            var cfg = ' . $i18n . ';

            function buildToggle() {
                var wrap = document.createElement("div");
                wrap.className = "ap-toggle-bar";
                wrap.innerHTML =
                    \'<span class="ap-toggle-label"><i class="material-icons" style="font-size:16px;">inventory_2</i>\' + escapeHtml(cfg.title) + \' :</span>\' +
                    \'<div class="ap-toggle-group" role="group">\' +
                        \'<button type="button" data-mode="0" class="\' + (cfg.current === "0" ? "ap-active ap-mode-0" : "") + \'"><i class="material-icons" style="font-size:14px;">visibility_off</i>\' + escapeHtml(cfg.hide) + \'</button>\' +
                        \'<button type="button" data-mode="1" class="\' + (cfg.current === "1" ? "ap-active ap-mode-1" : "") + \'"><i class="material-icons" style="font-size:14px;">visibility</i>\' + escapeHtml(cfg.all) + \'</button>\' +
                        \'<button type="button" data-mode="2" class="\' + (cfg.current === "2" ? "ap-active ap-mode-2" : "") + \'"><i class="material-icons" style="font-size:14px;">inventory</i>\' + escapeHtml(cfg.only) + \'</button>\' +
                    \'</div>\';
                wrap.addEventListener("click", function(e) {
                    var btn = e.target.closest("button[data-mode]");
                    if (!btn) return;
                    var mode = btn.getAttribute("data-mode");
                    var url = new URL(window.location.href);
                    if (mode === "0") {
                        url.searchParams.delete(cfg.paramName);
                    } else {
                        url.searchParams.set(cfg.paramName, mode);
                    }
                    // Retirer les params de pagination pour revenir en page 1
                    Array.from(url.searchParams.keys()).forEach(function(k) {
                        if (k.indexOf("[offset]") !== -1 || k === "product[offset]") {
                            url.searchParams.delete(k);
                        }
                    });
                    window.location.href = url.toString();
                });
                return wrap;
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"\x27]/g, function(m) {
                    return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#39;"}[m];
                });
            }

            function inject() {
                if (document.querySelector(".ap-toggle-bar")) return true;
                // Trouver le panneau de la grille produit
                var table = document.querySelector("table.grid-table, #product_grid table, [id^=\"product_grid\"] table");
                var panel = table ? table.closest(".card, .grid-panel, .js-grid-panel, .panel") : null;
                if (!panel) return false;
                panel.parentNode.insertBefore(buildToggle(), panel);
                return true;
            }

            function ready(fn) {
                if (document.readyState !== "loading") fn();
                else document.addEventListener("DOMContentLoaded", fn);
            }

            ready(function() {
                if (inject()) return;
                // Certaines grilles sont rendues après DOMContentLoaded (AJAX), on réessaye
                var tries = 0;
                var iv = setInterval(function() {
                    tries++;
                    if (inject() || tries > 30) clearInterval(iv);
                }, 200);
            });
        })();
        </script>';

        return $html;
    }

    /**
     * Hook PS 8.x - Modifie le QueryBuilder de la grille produit Symfony
     * Applique le filtre 3 états selon le param URL ap_show_archives :
     *   0 = masquer archives (défaut) / 1 = tout afficher / 2 = archives seules
     */
    public function hookActionProductGridQueryBuilderModifier($params)
    {
        $categoryIds = $this->getArchiveCategoryIds();
        if (empty($categoryIds)) {
            return;
        }

        /** @var \Doctrine\DBAL\Query\QueryBuilder $searchQueryBuilder */
        $searchQueryBuilder = $params['search_query_builder'];
        /** @var \PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface $searchCriteria */
        $searchCriteria = $params['search_criteria'];

        // IDs sécurisés (intval déjà appliqué dans getArchiveCategoryIds)
        $idsList = implode(',', $categoryIds);

        // Sous-requête EXISTS autonome : 1 si le produit est dans une catégorie archive
        $existsSubquery = sprintf(
            'EXISTS(SELECT 1 FROM `%scategory_product` cp_a WHERE cp_a.`id_product` = p.`id_product` AND cp_a.`id_category` IN (%s))',
            _DB_PREFIX_,
            $idsList
        );

        $mode = $this->getShowArchivesMode();

        switch ($mode) {
            case '1':
                // Tout afficher : aucun filtre
                return;

            case '2':
                // Archives seules
                $searchQueryBuilder->andWhere($existsSubquery);
                return;

            case '0':
            default:
                // Masquer archives (défaut), sauf si l'utilisateur filtre
                // explicitement sur une catégorie archive
                $filters = $searchCriteria->getFilters();
                if (!empty($filters['id_category']) && is_array($filters['id_category'])) {
                    $selected = array_filter(array_map('intval', $filters['id_category']));
                    if (!empty(array_intersect($selected, $categoryIds))) {
                        return;
                    }
                }
                $searchQueryBuilder->andWhere('NOT ' . $existsSubquery);
                return;
        }
    }

    /**
     * Vérifie si un filtre de recherche est actif sur la grille
     */
    protected function isGridSearchFilterActive($params)
    {
        if (!isset($params['search_criteria'])) {
            return false;
        }

        $filters = $params['search_criteria']->getFilters();

        // Vérifier les filtres de recherche textuelle
        $textFilterKeys = ['name', 'reference', 'ean13', 'isbn', 'upc', 'mpn', 'keywords'];

        foreach ($textFilterKeys as $key) {
            if (!empty($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hook legacy pour PrestaShop < 8.x ou mode compatibilité
     * Exclut les produits des catégories archives via une sous-requête NOT EXISTS
     * (autonome, pas de JOIN externe nécessaire — évite les conflits d'alias)
     */
    public function hookActionAdminProductsListingFieldsModifier($params)
    {
        if (!$this->isProductListingWithSearchFilter()) {
            return;
        }

        $categoryIds = $this->getArchiveCategoryIds();
        if (empty($categoryIds)) {
            return;
        }

        if (!isset($params['sql'])) {
            return;
        }

        $sql = $params['sql'];
        $idsList = implode(',', $categoryIds);

        $excludeCondition = sprintf(
            ' AND NOT EXISTS(SELECT 1 FROM `%scategory_product` cp_a WHERE cp_a.`id_product` = p.`id_product` AND cp_a.`id_category` IN (%s))',
            _DB_PREFIX_,
            $idsList
        );

        if (stripos($sql, 'WHERE') !== false) {
            // Insérer juste après le premier WHERE
            $sql = preg_replace('/\bWHERE\b/i', 'WHERE 1=1' . $excludeCondition . ' AND ', $sql, 1);
        } else {
            $sql .= ' WHERE 1=1' . $excludeCondition;
        }

        $params['sql'] = $sql;
    }

    /**
     * Vérifie si on est sur le listing produit avec un filtre de recherche actif (legacy)
     */
    protected function isProductListingWithSearchFilter()
    {
        $controller = $this->context->controller;

        // Vérifier qu'on est sur le contrôleur AdminProducts
        if (!isset($controller) || $controller->controller_name !== 'AdminProducts') {
            return false;
        }

        // Vérifier si un filtre de recherche par nom est actif
        $filterName = Tools::getValue('filter_column_name', '');

        // Vérifier les différentes façons dont PrestaShop peut filtrer
        $hasNameFilter = !empty($filterName)
            || !empty(Tools::getValue('filter_column_reference'))
            || !empty(Tools::getValue('filter_column_ean13'))
            || !empty(Tools::getValue('filter_column_isbn'))
            || !empty(Tools::getValue('filter_column_upc'))
            || !empty(Tools::getValue('filter_column_mpn'));

        return $hasNameFilter;
    }

}
