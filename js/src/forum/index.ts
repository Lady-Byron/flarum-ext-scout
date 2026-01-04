import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import DiscussionsSearchSource from 'flarum/forum/components/DiscussionsSearchSource';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import Link from 'flarum/common/components/Link';
import highlight from 'flarum/common/helpers/highlight';
import type Mithril from 'mithril';

app.initializers.add('clarkwinkelmann-scout', () => {
  // 扩展搜索下拉菜单中讨论结果的渲染
  extend(DiscussionsSearchSource.prototype, 'view', function(this: DiscussionsSearchSource, vdom: Mithril.Vnode[], query: string) {
    if (!Array.isArray(vdom)) return;

    const results = this.results.get(query.toLowerCase()) || [];
    if (results.length === 0) return;

    // 遍历 vdom，找到讨论结果项并增强
    vdom.forEach((vnode: any) => {
      if (!vnode?.attrs?.['data-index']) return;

      const dataIndex = vnode.attrs['data-index'];
      if (!dataIndex.startsWith('discussions')) return;

      // 从 data-index 提取讨论 ID (格式: "discussions123")
      const discussionId = dataIndex.replace('discussions', '');
      const discussion = results.find((d: any) => String(d.id()) === discussionId);

      if (!discussion) return;

      const titleHighlight = discussion.attribute('titleHighlight');
      const contentHighlight = discussion.attribute('contentHighlight');
      const mostRelevantPost = discussion.mostRelevantPost?.();

      // 构建标题内容：优先使用 ES 高亮
      const titleContent = titleHighlight
        ? m.trust(titleHighlight)
        : highlight(discussion.title() || '', query);

      // 构建摘要内容
      let excerptContent: Mithril.Children = null;
      if (contentHighlight) {
        excerptContent = m.trust(contentHighlight);
      } else if (mostRelevantPost) {
        const plain = mostRelevantPost.contentPlain?.();
        if (plain) {
          excerptContent = highlight(plain, query, 100);
        }
      }

      // 楼层直达链接
      const postNumber = mostRelevantPost?.number?.();
      const href = app.route.discussion(discussion, postNumber);

      // 替换 vnode 内容
      vnode.children = [
        <Link href={href}>
          <div className="DiscussionSearchResult-title">{titleContent}</div>
          {excerptContent && (
            <div className="DiscussionSearchResult-excerpt">{excerptContent}</div>
          )}
        </Link>
      ];
    });
  });

  // 扩展讨论列表页面的高亮显示（搜索结果页）
  extend(DiscussionListItem.prototype, 'view', function(this: DiscussionListItem, vdom: Mithril.Vnode) {
    const discussion = this.attrs.discussion;
    if (!discussion) return;

    const titleHighlight = discussion.attribute('titleHighlight');
    if (!titleHighlight) return;

    // 递归查找并替换标题元素
    const replaceTitle = (node: any): void => {
      if (!node || typeof node !== 'object') return;

      if (node.attrs?.className?.includes('DiscussionListItem-title')) {
        node.children = [m.trust(titleHighlight)];
        return;
      }

      if (Array.isArray(node.children)) {
        node.children.forEach(replaceTitle);
      } else if (node.children) {
        replaceTitle(node.children);
      }
    };

    replaceTitle(vdom);
  });
});
