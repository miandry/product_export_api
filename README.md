# Product Export API

Module Drupal pour exporter les produits (type de contenu `produit`) vers une autre plateforme.

## Endpoints

- `GET /api/products`
- `GET /api/products/{nid}`

## Parametres

Pour `GET /api/products`:

- `limit` (defaut `50`, max `100`)
- `offset` (defaut `0`)
- `after_nid` (optionnel, pagination curseur recommandee)
- `published` (optionnel: `0` ou `1`)
- `include_total` (optionnel: `1` pour calculer `total`, plus couteux)

## Reponse

Le endpoint liste retourne:

- `total`, `limit`, `offset`, `after_nid`, `next_after_nid`, `count`
- pagination: `has_prev`, `has_next`, `prev_offset`, `next_offset`
- `ids`: tableau des `nid` produits pour la page courante

Le detail complet d'un produit est disponible via `GET /api/products/{nid}`:

- Infos de base: `nid`, `uuid`, `type`, `langcode`, `title`, `status`, `created`, `changed`
- `fields`: tous les champs personnalises
  - champs fichier/image: URL absolue incluse
  - entity reference: id + label + type + bundle

## Activation

1. Activer le module `Product Export API`.
2. Vider le cache Drupal (`drush cr`).
