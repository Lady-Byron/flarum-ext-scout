import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import DiscussionsSearchSource from 'flarum/forum/components/DiscussionsSearchSource';
import DiscussionsSearchItem from 'flarum/forum/components/DiscussionsSearchItem';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import Link from 'flarum/common/components/Link';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

/**
 * 转义正则表达式特殊字符
 */
function escapeRegExp(string: string): string {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * 安全的高亮函数
 */
function safeHighlight(
  string: string,
  phrase?: string,
  length?: number
): Mithril.Vnode<any, any> | string {
  if (!phrase && !length) return string;

  let highlighted = string;
  let start = 0;

  // 创建安全的正则表达式
  const regexp = new RegExp(escapeRegExp(phrase ?? ''), 'gi');

  // 截断逻辑
  if (length) {
    if (phrase) {
      const matchIndex = string.search(regexp);
      start = Math.max(0, matchIndex === -1 ? 0 : matchIndex - Math.floor(length / 2));
    }
    highlighted = string.substring(start, start + length);
    if (start > 0) highlighted = '...' + highlighted;
    if (start + length < string.length) highlighted = highlighted + '...';
  }

  // 转义 HTML 实体
  highlighted = $('<div/>').text(highlighted).html() as string;
  
  // 高亮匹配
  if (phrase) {
    highlighted = highlighted.replace(regexp, '<mark>$&</mark>');
  }

  return m.trust(highlighted);
}

app.initializers.add('lady-byron-scout', () => {
  
  // 🔧 关键修复：覆盖 DiscussionsSearchItem 的 viewItems 方法
  override(DiscussionsSearchItem.prototype, 'viewItems', function (this: DiscussionsSearchItem, original: () => ItemList<Mithril.Children>) {
    const items = new ItemList<Mithril.Children>();

    // 优先使用 ES 返回的高亮，否则使用 safeHighlight
    const titleHighlight = this.discussion.attribute('titleHighlight');
    const contentHighlight = this.discussion.attribute('contentHighlight');

    const titleContent = titleHighlight
      ? m.trust(titleHighlight)
      : safeHighlight(this.discussionTitle(), this.query);

    items.add(
      'discussion-title',
      <div className="DiscussionSearchResult-title">{titleContent}</div>,
      90
    );

    if (this.mostRelevantPost) {
      const excerptContent = contentHighlight
        ? m.trust(contentHighlight)
        : safeHighlight(this.mostRelevantPostContent() ?? '', this.query, 100);

      items.add(
        'most-relevant',
        <div className="DiscussionSearchResult-excerpt">{excerptContent}</div>,
        80
      );
    }

    return items;
  });

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
        if (cls.includes('DiscussionListItem-title') && titleHighlight) {
          node.children = [m.trust(titleHighlight)];
          return;
        }
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
