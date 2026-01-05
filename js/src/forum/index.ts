import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import DiscussionsSearchSource from 'flarum/forum/components/DiscussionsSearchSource';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import Link from 'flarum/common/components/Link';
import highlight from 'flarum/common/helpers/highlight';
import type Mithril from 'mithril';

app.initializers.add('lady-byron-scout', () => {
  // 扩展搜索下拉菜单中讨论结果的渲染
  extend(
    DiscussionsSearchSource.prototype,
    'view',
    function (this: DiscussionsSearchSource, vdom: Mithril.Vnode[], query: string) {
      if (!Array.isArray(vdom)) return;

      const results = this.results.get(query.toLowerCase()) || [];
      if (!results.length) return;

      vdom.forEach((vnode: any) => {
        const dataIndex = vnode?.attrs?.['data-index'];
        if (!dataIndex || typeof dataIndex !== 'string') return;
        if (!dataIndex.startsWith('discussions')) return;

        const discussionId = dataIndex.replace('discussions', '');
        const discussion = results.find((d: any) => String(d.id()) === discussionId);
        if (!discussion) return;

        const titleHighlight = discussion.attribute('titleHighlight');
        const contentHighlight = discussion.attribute('contentHighlight');
        const mostRelevantPost = discussion.mostRelevantPost?.();

        const titleContent: Mithril.Children = titleHighlight
          ? m.trust(titleHighlight)
          : highlight(discussion.title() || '', query);

        let excerptContent: Mithril.Children = null;
        if (contentHighlight) {
          excerptContent = m.trust(contentHighlight);
        } else if (mostRelevantPost) {
          const plain = mostRelevantPost.contentPlain?.();
          if (plain) excerptContent = highlight(plain, query, 100);
        }

        const postNumber = mostRelevantPost?.number?.();
        const href = app.route.discussion(discussion, postNumber);

        vnode.children = [
          m(
            Link,
            { href },
            [
              m('div', { className: 'DiscussionSearchResult-title' }, titleContent),
              excerptContent ? m('div', { className: 'DiscussionSearchResult-excerpt' }, excerptContent) : null,
            ].filter(Boolean)
          ),
        ];
      });
    }
  );

  // 扩展讨论列表页面的高亮显示（搜索结果页）
  extend(DiscussionListItem.prototype, 'view', function (this: DiscussionListItem, vdom: Mithril.Vnode) {
    const discussion = this.attrs.discussion;
    if (!discussion) return;

    const titleHighlight = discussion.attribute('titleHighlight');
    const contentHighlight = discussion.attribute('contentHighlight');

    // 如果都没有高亮，直接返回
    if (!titleHighlight && !contentHighlight) return;

    // 递归查找并替换元素
    const replaceHighlights = (node: any): void => {
      if (!node || typeof node !== 'object') return;

      const cls = node.attrs?.className || node.attrs?.class || '';
      if (typeof cls === 'string') {
        // 替换标题
        if (cls.includes('DiscussionListItem-title') && titleHighlight) {
          node.children = [m.trust(titleHighlight)];
          return;
        }
        // 替换正文摘要
        if (cls.includes('DiscussionListItem-excerpt') && contentHighlight) {
          node.children = [m.trust(contentHighlight)];
          return;
        }
      }

      if (Array.isArray(node.children)) {
        node.children.forEach(replaceHighlights);
      } else if (node.children) {
        replaceHighlights(node.children);
      }
    };

    replaceHighlights(vdom);
  });
});
