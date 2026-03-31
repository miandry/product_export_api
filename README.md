# Product Export API

Module Drupal pour exporter les produits (type de contenu `produit`) vers une autre plateforme.

## Endpoints

- `GET /api/products`
- `GET /api/products/{nid}`

## Parametres

Pour `GET /api/products`:

- `limit` (defaut `100`, max `500`)
- `offset` (defaut `0`)
- `published` (optionnel: `0` ou `1`)

## Reponse

Le endpoint liste retourne:

- `total`, `limit`, `offset`, `count`
- pagination: `has_prev`, `has_next`, `prev_offset`, `next_offset`
- `links`: `self`, `first`, `last`, `prev`, `next`
- `ids`: tableau des `nid` produits pour la page courante

Le detail complet d'un produit est disponible via `GET /api/products/{nid}`:

- Infos de base: `nid`, `uuid`, `type`, `langcode`, `title`, `status`, `created`, `changed`
- `fields`: tous les champs personnalises
  - champs fichier/image: URL absolue incluse
  - entity reference: id + label + type + bundle

## Activation

1. Activer le module `Product Export API`.
2. Vider le cache Drupal (`drush cr`).
