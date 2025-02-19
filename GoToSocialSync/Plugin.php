<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * GoToSocial Sync for Typecho
 * 将新文章自动同步到 GoToSocial/Mastodon/Pleroma 实例
 * 
 * @package GoToSocialSync 
 * @version 1.0.4
 * @author jkjoy
 * @link https://github.com/jkjoy
 */
class GoToSocialSync_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return string
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('GoToSocialSync_Plugin', 'syncToGoToSocial');
        return _t('插件已经激活，请配置 GoToSocial 实例信息');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return string
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        return _t('插件已被禁用');
    }
    
    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $instance_url = new Typecho_Widget_Helper_Form_Element_Text(
            'instance_url',
            NULL,
            'https://social.sgcd.net',
            _t('GoToSocial 实例地址'),
            _t('请输入您的 GoToSocial/Mastodon/Pleroma  实例地址，如 https://social.sgcd.net')
        );
        $form->addInput($instance_url);
        
        $access_token = new Typecho_Widget_Helper_Form_Element_Text(
            'access_token',
            NULL,
            '',
            _t('Access Token'),
            _t('请输入您的 GoToSocial/Mastodon/Pleroma  Access Token')
        );
        $form->addInput($access_token);

        $summary_length = new Typecho_Widget_Helper_Form_Element_Text(
            'summary_length',
            NULL,
            '100',
            _t('摘要长度'),
            _t('当文章没有AI摘要时，从正文提取的摘要长度（字数）')
        );
        $form->addInput($summary_length);
    }
    
    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
        // 个人用户不需要配置
    }

    /**
     * 获取文章摘要
     * 
     * @param array $row 文章数据
     * @param int $length 摘要长度
     * @return string
     */
    private static function getPostSummary($row, $length)
    {
        // 优先获取 summary 字段
        $db = Typecho_Db::get();
        $fields = $db->fetchRow($db->select('str_value')
            ->from('table.fields')
            ->where('cid = ?', $row['cid'])
            ->where('name = ?', 'summary'));
        
        if (!empty($fields['str_value'])) {
            return $fields['str_value'];
        }

        // 如果没有 AIsummary，则从正文提取
        $text = strip_tags($row['text']);
        $text = str_replace(['<!--markdown-->', "\r", "\n"], '', $text);
        return mb_substr($text, 0, $length, 'UTF-8');
    }
    
    /**
     * 同步文章到 GoToSocial
     * 
     * @param array $contents 文章内容
     * @param Widget_Contents_Post_Edit $class
     * @return void
     */
    public static function syncToGoToSocial($contents, $class)
    {
        // Debug log
        error_log('GoToSocialSync triggered with contents: ' . print_r($contents, true));

        // 检查是否为文章发布
        if (!isset($contents['type']) || $contents['type'] != 'post' || $contents['visibility'] != 'publish') {
            error_log('Not a published post');
            return;
        }

        // 获取系统配置
        $options = Helper::options();
        $instance_url = rtrim($options->plugin('GoToSocialSync')->instance_url, '/');
        $access_token = $options->plugin('GoToSocialSync')->access_token;
        $summary_length = intval($options->plugin('GoToSocialSync')->summary_length);

        // 检查必要配置
        if (empty($instance_url) || empty($access_token)) {
            error_log('GoToSocial configuration is incomplete.');
            return;
        }

        // 构建API URL
        $api_url = $instance_url . '/api/v1/statuses';

        try {
            // 获取文章标题
            $title = isset($contents['title']) ? $contents['title'] : '';
            
            // 获取文章数据
            $db = Typecho_Db::get();
            $row = $db->fetchRow($db->select()
                ->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->where('title = ?', $title)
                ->order('created', Typecho_Db::SORT_DESC)
                ->limit(1));

            if (empty($row)) {
                error_log('Could not find the post in database');
                return;
            }

            // 使用 Widget_Abstract_Contents 来获取正确的永久链接
            $widget = new Widget_Abstract_Contents($class->request, $class->response, $class->parameter);
            $widget->push($row);
            $permalink = $widget->permalink;

            // 如果上面的方法获取失败，尝试使用另一种方式
            if (empty($permalink)) {
                $routeExists = (NULL != Typecho_Router::get('post'));
                if ($routeExists) {
                    $permalink = Typecho_Router::url('post', $row);
                } else {
                    // 如果没有配置路由，使用基本链接格式
                    $permalink = Typecho_Common::url('index.php/archives/' . $row['cid'], $options->siteUrl);
                }
            }

            // 获取摘要
            $summary = self::getPostSummary($row, $summary_length);

            // 构建消息内容 (Markdown格式)
            $message = "## {$title}\n\n";
            $message .= $summary . "\n\n";
            $message .= "🔗 阅读全文: {$permalink}";

            // 准备发送的数据
            $post_data = [
                'status' => $message,
                'visibility' => 'public',
                'content_type' => 'text/markdown'
            ];

            error_log('Sending data to GoToSocial: ' . print_r($post_data, true));

            // 初始化 CURL
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $api_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($post_data),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $access_token,
                    'Content-Type: application/json',
                    'Accept: */*',
                    'User-Agent: TypechoGoToSocialSync/1.0.4',
                    'Host: ' . parse_url($api_url, PHP_URL_HOST),
                    'Connection: keep-alive'
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);

            // 执行请求
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // 错误处理
            if (curl_errno($ch)) {
                error_log('Curl error when syncing to GoToSocial: ' . curl_error($ch));
            } else {
                error_log("GoToSocial sync response (HTTP $http_code): " . $response);
            }

            curl_close($ch);

        } catch (Exception $e) {
            error_log('Exception in GoToSocialSync: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
        }
    }
}