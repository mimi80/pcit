# Builds

## List

This returns a list of builds for the current user. The result is paginated. The default limit is 100.

| Method | URL       |
| :----- | :-------- |
| `GET`  | `/builds` |

## Find By Repo

This returns a list of builds for an individual repository. It is possible to use the repository id or slug in the request. The result is paginated. Each request will return 25 results.


| Method | URL                                              |
| :----- | :----------------------------------------------- |
| `GET`  | `/repo/{git_type}/{username}/{repo.name}/builds` |

**Example:** `GET` `/repo/github_app/khs1994-php/khsci/builds`

## Find

This returns a single build.

> 返回某个构建的详情

| Method | URL                 |
| :----- | :------------------ |
| `GET`  | `/build/{build.id}` |

**Example:** `GET` `/build/666`

## Cancel

This cancels a currently running build. It will set the build and associated jobs to "state": "canceled".

> 取消某个构建

| Method  | URL                        |
| :-----  | :------------------------- |
| `POST`  | `/build/{build.id}/cancel` |

**Example:** `POST` `/build/666/cancel`

## Restart

This restarts a build that has completed or been canceled.

> 重新开始某个构建

| Method  | URL                         |
| :-----  | :-------------------------- |
| `POST`  | `/build/{build.id}/restart` |

**Example:** `POST` `/build/666/restart`