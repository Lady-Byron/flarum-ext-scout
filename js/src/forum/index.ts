import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import DiscussionsSearchSource from 'flarum/forum/components/DiscussionsSearchSource';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import Link from 'flarum/common/components/Link';
import highlight from 'flarum/common/helpers/highlight';
import type Mithril from 'mithril';

/**
 * 转义正则表达式特殊字符
 */
function escapeRegExp(string: string): string {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * 安全的高亮函数包装器
 * 将搜索词转换为转义后的正则表达式，避免特殊字符导致的错误
 */
function safeHighlight(
  text: string,
  phrase?: string | RegExp,
  length?: number
): Mithril.Vnode<any, any> | string {
  if (!phrase) {
    return highlight(text, phrase, length);
  }
  
  // 如果已经是 RegExp，直接使用
  if (phrase instanceof RegExp) {
    return highlight(text, phrase, length);
  }
  
  // 字符串转换为安全的正则表达式
  const safeRegex = new RegExp(escapeRegExp(phrase), 'gi');
  return highlight(text, safeRegex, length);
}

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

        // 使用 safeHighlight 替代 highlight
        const titleContent: Mithril.Children = titleHighlight
          ? m.trust(titleHighlight)
          : safeHighlight(discussion.title() || '', query);

        let excerptContent: Mithril.Children = null;
        if (contentHighlight) {
          excerptContent = m.trust(contentHighlight);
        } else if (mostRelevantPost) {
          const plain = mostRelevantPost.contentPlain?.();
          // 使用 safeHighlight 替代 highlight
          if (plain) excerptContent = safeHighlight(plain, query, 100);
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
    if (!titleHighlight && !contentHighlight) return;

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
        if (cls.includes('item-excerpt') && contentHighlight) {
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
