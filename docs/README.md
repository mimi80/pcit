# PCIT Documents

**PCIT** 划分为 **CE** 和 **EE** 两大版本。

**CE** 供用户预览，**不提供** 项目构建功能。

**EE** 需要用户在 **自有主机** 私有部署，**支持** 项目构建。

## 体验 PCIT CE

### GitHub 新建仓库

### 安装 PCIT GitHub App

在 https://github.com/apps/pcit-ce 点击右边的 `Install` 按钮，在稍后跳转的页面中选择你新建的 **仓库**

### 体验 Issue 内容中英互译

在你的 **仓库** 新建一个 Issue (内容不能为空)，提交之后，可以看到 `pcit[bot]` 回复了这个 Issue

## PCIT EE

**PCIT EE 需要自行部署，部署方法请看下一节**

### 项目测试、构建

#### 准备 PHP 项目

* 这里以 `khs1994-docker/php-demo` 为例

```bash
$ git clone https://github.com/khs1994-docker/php-demo
```

#### 编辑 `.pcit.yml` 文件

为降低入门难度，参考了 `Drone CI` 与 `Travis CI` 构建配置模板，**PCIT** 的构建配置文件名为 `.pcit.yml`

#### 推送到 GitHub

```bash
$ cd php-demo

# 编辑文件，然后推送到 GitHub

$ git add .

$ git commit -m "Test PCIT"

$ git push origin master
```

在 **GitHub** 点击 `commits`，点击 commit 信息后的小图标，进入到构建详情页，查看构建过程及结果

## 私有部署 PCIT EE

**PCIT** 由 **PHP 后端**（Webhooks Server + Daemon CLI）和 **GitHub App** 组成。

[Coming Soon !](https://github.com/khs1994-php/pcit/blob/master/docs/install/ee.md)

# About PCIT CE and EE

| Compare       | PCIT CE             | PCIT EE      |
| :------------ | :------------------- | :------------ |
| Pricing       | Free                 | **$0**/month  |
| 支持方式       | 社区支持                    | 项目开发者一对一支持    |
| 使用方式       | 一键安装 `GitHub App` 体验  | **自有主机** 私有部署 |
| 仓库数量       | 公共仓库不限制               | 公共仓库不限制       |
| 协作者         | 不限制协作者                | 不限制协作者        |
| 通知方式       | Git Issues                | Git Issues    |
| 项目构建       | 不支持                    | 支持            |
| Issue Comment  | 支持                     | 支持            |

* 协作者数量取决于 Git 服务商的限制，PCIT 不做任何限制

* 自有主机包括 **树莓派**、**家用电脑**、**笔记本**、**云主机**

* 本项目优先支持 GitHub，部分功能可能不支持其他 Git 服务商

## 未来 CE 和 EE 版本价格问题

总的来说 **CE** 和 **EE** 的最大区别就是 **是否需要自己部署**。

> 为什么 CE 不提供构建功能?

由于 **CE** 部署在一个 **1G 1核** 的云主机上，配置较低，暂不提供项目构建功能。

本项目承诺 **永久免费**，但对 **技术支持服务** 收费（ **如何收费待定** ）。
