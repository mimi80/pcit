# Caches

A list of caches.

## List

This returns all the caches for a repository.

> 返回仓库的构建缓存列表

| Method | URL                                              |
| :----- | :----------------------------------------------- |
| `GET`  | `/repo/{username}/{repo.name}/caches` |

**Example:** `GET` `/repo/khs1994-php/pcit/caches`

## Delete

This deletes all caches for a repository.

> 删除仓库的所有构建缓存

| Method    | URL                                              |
| :-----    | :----------------------------------------------- |
| `DELETE`  | `/repo/{username}/{repo.name}/caches` |

**Example:** `DELETE` `/repo/khs1994-php/pcit/caches`
